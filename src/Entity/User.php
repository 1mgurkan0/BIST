<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Bu e-posta adresi zaten kayıtlı.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(length: 6, nullable: true)]
    private ?string $resetCode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resetCodeExpiresAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $resetAttempts = 0;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function generateResetCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->resetCode          = $code;
        $this->resetCodeExpiresAt = new \DateTimeImmutable('+2 minutes', new \DateTimeZone('UTC'));
        $this->resetAttempts      = 0;
        return $code;
    }

    public function isResetCodeValid(string $code): bool
    {
        if ($this->resetCode === null || $this->resetCodeExpiresAt === null) {
            return false;
        }

        if ($this->resetCodeExpiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return hash_equals($this->resetCode, $code);
    }

    public function clearResetCode(): void
    {
        $this->resetCode          = null;
        $this->resetCodeExpiresAt = null;
        $this->resetAttempts      = 0;
    }

    public function incrementResetAttempts(): void
    {
        $this->resetAttempts++;
    }

    public function isResetCodeBlocked(): bool
    {
        return $this->resetAttempts >= 5;
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = strtolower(trim($email)); return $this; }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getFullName(): string { return trim($this->firstName . ' ' . $this->lastName); }

    public function getUserIdentifier(): string { return (string) $this->email; }

    public function getRoles(): array
    {
        $roles   = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function isVerified(): bool { return $this->isVerified; }
    public function setIsVerified(bool $isVerified): static { $this->isVerified = $isVerified; return $this; }

    public function getResetCode(): ?string { return $this->resetCode; }
    public function getResetCodeExpiresAt(): ?\DateTimeImmutable { return $this->resetCodeExpiresAt; }
    public function getResetAttempts(): int { return $this->resetAttempts; }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }

    #[\Deprecated]
    public function eraseCredentials(): void {}
}
