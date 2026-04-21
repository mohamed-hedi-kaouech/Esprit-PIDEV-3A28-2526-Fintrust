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

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            return $this->json(['ok' => false, 'message' => 'Coordonnees invalides.'], Response::HTTP_BAD_REQUEST);
        }

        $previous = $this->readPreviousContext($user);
        if ($this->isRecentlyConfirmed($previous, $latitude, $longitude)) {
            return $this->json(['ok' => true, 'message' => 'Localisation deja confirmee recemment.']);
        }

        $place = $this->reverseGeocode($latitude, $longitude);
        $label = $place['label'] ?? sprintf('%.5f, %.5f', $latitude, $longitude);
        $detail = $place['detail'] ?? 'precision navigateur';

        $this->notificationService->notify(
            $user,
            sprintf(
                'Localisation precise confirmee pres de %s (%s). Precision estimee : %.0f m.',
                $label,
                $detail,
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
            'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return $this->json(['ok' => true, 'label' => $label, 'detail' => $detail]);
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
                    'zoom' => 14,
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
        $label = $this->firstNonEmpty([
            $address['suburb'] ?? null,
            $address['town'] ?? null,
            $address['city'] ?? null,
            $address['municipality'] ?? null,
            $address['county'] ?? null,
            $data['name'] ?? null,
        ]);
        $country = $this->firstNonEmpty([
            $address['state'] ?? null,
            $address['country'] ?? null,
        ]);

        return [
            'label' => $label ?: sprintf('%.5f, %.5f', $latitude, $longitude),
            'detail' => $country ?: 'coordonnees navigateur',
        ];
    }

    /**
     * @param array<string,mixed>|null $previous
     */
    private function isRecentlyConfirmed(?array $previous, float $latitude, float $longitude): bool
    {
        if ($previous === null) {
            return false;
        }

        $checkedAt = isset($previous['checkedAt']) ? strtotime((string) $previous['checkedAt']) : false;
        if (!$checkedAt || time() - $checkedAt > 86400) {
            return false;
        }

        return abs((float) ($previous['latitude'] ?? 999) - $latitude) < 0.01
            && abs((float) ($previous['longitude'] ?? 999) - $longitude) < 0.01;
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
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return null;
    }
}
