<?php

namespace App\Controller\Front;

use App\Entity\User\User;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[IsGranted('ROLE_CLIENT')]
#[Route('/espace-client/securite', name: 'front_security_')]
class SecurityLocationController extends AbstractController
{
    private const LOCATION_CONTEXT_DIR = 'security-browser-location';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly NotificationService $notificationService,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/localisation-navigateur', name: 'browser_location', methods: ['POST'])]
    public function browserLocation(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('browser_location', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['ok' => false, 'message' => 'Token invalide.'], Response::HTTP_FORBIDDEN);
        }

        /** @var User $user */
        $user = $this->getUser();
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['ok' => false, 'message' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $latitude = (float) ($payload['latitude'] ?? 0);
        $longitude = (float) ($payload['longitude'] ?? 0);
        $accuracy = (float) ($payload['accuracy'] ?? 0);
        $force = (bool) ($payload['force'] ?? false);

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $this->json(['ok' => false, 'message' => 'Coordonnees invalides.'], Response::HTTP_BAD_REQUEST);
        }

        $previous = $this->readPreviousContext($user);
        if ($this->isRecentlyConfirmed($previous, $latitude, $longitude, $force)) {
            return $this->json(['ok' => true, 'message' => 'Localisation deja confirmee recemment.']);
        }

        $place = $this->reverseGeocode($latitude, $longitude);
        $label = $place['label'] ?? sprintf('%.5f, %.5f', $latitude, $longitude);
        $detail = $place['detail'] ?? 'precision navigateur';
        $coordinates = sprintf('%.6f, %.6f', $latitude, $longitude);

        $this->notificationService->notify(
            $user,
            sprintf(
                'Localisation navigateur confirmee : %s. Zone detaillee : %s. Coordonnees : %s. Precision estimee : %.0f m.',
                $label,
                $detail,
                $coordinates,
                max(0, $accuracy)
            ),
            'INFO'
        );

        $this->writeCurrentContext($user, [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
            'label' => $label,
            'detail' => $detail,
            'coordinates' => $coordinates,
            'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return $this->json(['ok' => true, 'label' => $label, 'detail' => $detail, 'coordinates' => $coordinates]);
    }

    #[Route('/localisation-navigateur/statut', name: 'browser_location_status', methods: ['GET'])]
    public function browserLocationStatus(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $previous = $this->readPreviousContext($user);

        if ($previous === null) {
            return $this->json(['needsLocation' => true]);
        }

        $checkedAt = isset($previous['checkedAt']) ? strtotime((string) $previous['checkedAt']) : false;
        if (!$checkedAt || time() - $checkedAt > 86400) {
            return $this->json(['needsLocation' => true]);
        }

        return $this->json([
            'needsLocation' => false,
            'label' => $previous['label'] ?? null,
            'detail' => $previous['detail'] ?? null,
        ]);
    }

    /**
     * @return array{label:string,detail:string}
     */
    private function reverseGeocode(float $latitude, float $longitude): array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://nominatim.openstreetmap.org/reverse', [
                'query' => [
                    'format' => 'jsonv2',
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'zoom' => 18,
                    'addressdetails' => 1,
                    'accept-language' => 'fr',
                ],
                'headers' => [
                    'User-Agent' => 'FinTrust-Symfony-Security/1.0',
                ],
                'timeout' => 4,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable) {
            $data = [];
        }

        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        $localParts = $this->uniqueNonEmpty([
            $data['name'] ?? null,
            $address['road'] ?? null,
            $address['neighbourhood'] ?? null,
            $address['quarter'] ?? null,
            $address['suburb'] ?? null,
            $address['city_district'] ?? null,
            $address['village'] ?? null,
            $address['town'] ?? null,
            $address['city'] ?? null,
            $address['municipality'] ?? null,
        ]);
        $areaParts = $this->uniqueNonEmpty([
            $address['state_district'] ?? null,
            $address['county'] ?? null,
            $address['state'] ?? null,
            $address['country'] ?? null,
        ]);
        $label = implode(', ', array_slice($localParts, 0, 4));
        $detail = implode(', ', array_slice($areaParts, 0, 3));

        return [
            'label' => $label ?: sprintf('%.5f, %.5f', $latitude, $longitude),
            'detail' => $detail ?: ((string) ($data['display_name'] ?? 'coordonnees navigateur')),
        ];
    }

    /**
     * @param array<string,mixed>|null $previous
     */
    private function isRecentlyConfirmed(?array $previous, float $latitude, float $longitude, bool $force = false): bool
    {
        if ($previous === null) {
            return false;
        }

        $checkedAt = isset($previous['checkedAt']) ? strtotime((string) $previous['checkedAt']) : false;
        $maxAge = $force ? 300 : 86400;
        if (!$checkedAt || time() - $checkedAt > $maxAge) {
            return false;
        }

        return abs((float) ($previous['latitude'] ?? 999) - $latitude) < 0.001
            && abs((float) ($previous['longitude'] ?? 999) - $longitude) < 0.001;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readPreviousContext(User $user): ?array
    {
        $path = $this->getContextPath($user);
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function writeCurrentContext(User $user, array $context): void
    {
        $dir = $this->projectDir . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . self::LOCATION_CONTEXT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->getContextPath($user), json_encode($context, JSON_PRETTY_PRINT));
    }

    private function getContextPath(User $user): string
    {
        return $this->projectDir
            . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . self::LOCATION_CONTEXT_DIR
            . DIRECTORY_SEPARATOR . 'user_' . $user->getId() . '.json';
    }

    /**
     * @param array<int,mixed> $values
     *
     * @return array<int,string>
     */
    private function uniqueNonEmpty(array $values): array
    {
        $items = [];
        foreach ($values as $value) {
            $string = trim((string) $value);
            if ($string !== '' && !in_array($string, $items, true)) {
                $items[] = $string;
            }
        }

        return $items;
    }
}
