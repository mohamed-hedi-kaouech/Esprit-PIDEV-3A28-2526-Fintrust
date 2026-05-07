<?php

namespace App\Tests\Service;

use App\Entity\Loan\Loan;
use App\Entity\Loan\Repayment;
use App\Entity\User\User;
use App\Repository\Loan\LoanRepository;
use App\Repository\Loan\RepaymentRepository;
use App\Service\Loan\LoanService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LoanServiceTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private LoanRepository $loanRepository;
    private RepaymentRepository $repaymentRepository;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->loanRepository = $this->createMock(LoanRepository::class);
        $this->repaymentRepository = $this->createMock(RepaymentRepository::class);
    }

    public function testCalculateMonthlyPaymentWithoutInterest(): void
    {
        $service = $this->createService();
        $loan = $this->createLoan('1200.00', 12, '0.00');

        self::assertSame(100.0, $service->calculateMonthlyPayment($loan));
    }

    public function testGenerateRepaymentPreviewReturnsOneRowPerMonth(): void
    {
        $service = $this->createService();
        $loan = $this->createLoan('1200.00', 12, '12.00');

        $preview = $service->generateRepaymentPreview($loan);

        self::assertCount(12, $preview);
        self::assertSame(1, $preview[0]['month']);
        self::assertSame(12, $preview[11]['month']);
    }

    public function testGetNextUnpaidRepaymentReturnsFirstUnpaidInstallment(): void
    {
        $service = $this->createService();
        $loan = $this->createLoan('1000.00', 3, '10.00');

        $paidRepayment = (new Repayment())
            ->setLoan($loan)
            ->setMonth(1)
            ->setMonthlyPayment('100.00')
            ->setCapitalPart('90.00')
            ->setInterestPart('10.00')
            ->setStartingBalance('1000.00')
            ->setRemainingBalance('910.00')
            ->setStatus('PAID');

        $unpaidRepayment = (new Repayment())
            ->setLoan($loan)
            ->setMonth(2)
            ->setMonthlyPayment('100.00')
            ->setCapitalPart('92.00')
            ->setInterestPart('8.00')
            ->setStartingBalance('910.00')
            ->setRemainingBalance('818.00')
            ->setStatus('UNPAID');

        $loan->addRepayment($paidRepayment);
        $loan->addRepayment($unpaidRepayment);

        self::assertSame($unpaidRepayment, $service->getNextUnpaidRepayment($loan));
    }

    public function testMarkRepaymentPaidCompletesLoanWhenAllRepaymentsArePaid(): void
    {
        $service = $this->createService();
        $loan = $this->createLoan('1000.00', 2, '10.00');
        $loan->setStatus('ACTIVE');
        $loan->setRemainingPrincipal('100.00');

        $repayment = (new Repayment())
            ->setLoan($loan)
            ->setMonth(2)
            ->setMonthlyPayment('100.00')
            ->setCapitalPart('100.00')
            ->setInterestPart('0.00')
            ->setStartingBalance('100.00')
            ->setRemainingBalance('0.00')
            ->setStatus('UNPAID');

        $otherRepayment = (new Repayment())
            ->setLoan($loan)
            ->setMonth(1)
            ->setMonthlyPayment('100.00')
            ->setCapitalPart('100.00')
            ->setInterestPart('0.00')
            ->setStartingBalance('200.00')
            ->setRemainingBalance('100.00')
            ->setStatus('PAID');

        $loan->addRepayment($otherRepayment);
        $loan->addRepayment($repayment);

        $this->entityManager->expects(self::once())->method('flush');

        $service->markRepaymentPaid($repayment);

        self::assertSame('PAID', $repayment->getStatus());
        self::assertSame('0.00', $loan->getRemainingPrincipal());
        self::assertSame('COMPLETED', $loan->getStatus());
    }

    public function testPayRepaymentThrowsExceptionWhenLoanDoesNotBelongToUser(): void
    {
        $service = $this->createService();
        $owner = $this->createUserWithId(10);
        $loan = $this->createLoan('1000.00', 3, '10.00');
        $loan->setUser($owner);
        $loan->setStatus('ACTIVE');

        $repayment = (new Repayment())
            ->setLoan($loan)
            ->setMonth(1)
            ->setMonthlyPayment('100.00')
            ->setCapitalPart('90.00')
            ->setInterestPart('10.00')
            ->setStartingBalance('1000.00')
            ->setRemainingBalance('910.00')
            ->setStatus('UNPAID');

        $loan->addRepayment($repayment);

        $this->repaymentRepository
            ->expects(self::once())
            ->method('find')
            ->with(1)
            ->willReturn($repayment);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Access denied');

        $service->payRepayment(1, 99);
    }

    private function createService(): LoanService
    {
        return new LoanService(
            $this->entityManager,
            $this->loanRepository,
            $this->repaymentRepository
        );
    }

    private function createLoan(string $amount, int $duration, string $interestRate): Loan
    {
        return (new Loan())
            ->setLoanType('PERSONAL')
            ->setAmount($amount)
            ->setDuration($duration)
            ->setInterestRate($interestRate)
            ->setRemainingPrincipal($amount)
            ->setStatus('PENDING')
            ->setCreatedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));
    }

    private function createUserWithId(int $id): User
    {
        $user = (new User())
            ->setNom('Loan')
            ->setPrenom('Owner')
            ->setEmail('loan.owner@example.com');

        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $id);

        return $user;
    }
}
