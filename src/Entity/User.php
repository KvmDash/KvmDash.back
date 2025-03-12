<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
/**
 * User Entity
 * 
 * Diese Klasse repräsentiert einen Benutzer im System. Sie wird von Doctrine ORM
 * verwaltet und implementiert die Symfony Security-Interfaces für die Authentifizierung.
 * 
 * Implementierte Interfaces:
 * - UserInterface: Benötigt für die Symfony Security-Komponente
 * - PasswordAuthenticatedUserInterface: Ermöglicht die Authentifizierung mittels Passwort
 */
#[ORM\Entity]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{

    private ?TranslatorInterface $translator = null;

    // Setter für den Translator
    public function setTranslator(TranslatorInterface $translator): void 
    {
        $this->translator = $translator;
    }

    /**
     * Die eindeutige ID des Benutzers
     * 
     * @var int Diese ID wird automatisch von der Datenbank generiert (Auto-Increment)
     * und dient als Primärschlüssel für den Datensatz.
     */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    /**
     * Die E-Mail-Adresse des Benutzers
     * 
     * @var string|null Die E-Mail wird als Benutzername verwendet und muss eindeutig sein.
     * Sie dient zur Identifikation des Benutzers im System.
     */
    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    /**
     * Die Rollen des Benutzers im System
     * 
     * @var array<int,string> Ein Array von Rollenbezeichnern, die die Berechtigungen
     * des Benutzers definieren (z.B. 'ROLE_ADMIN', 'ROLE_USER', etc.).
     * Jeder Benutzer hat mindestens die Rolle 'ROLE_USER'.
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * Das verschlüsselte Passwort des Benutzers
     * 
     * @var string|null Enthält den Hash des Passworts, nicht das Passwort im Klartext.
     * Die Verschlüsselung sollte durch einen PasswordHasher-Service erfolgen.
     */
    #[ORM\Column]
    private ?string $password = null;

    /**
     * Konstruktor für die User-Klasse
     * 
     * Initialisiert die ID mit 0, was später durch Doctrine überschrieben wird,
     * wenn der Benutzer in der Datenbank gespeichert wird.
     */
    public function __construct()
    {
        $this->id = 0;
    }

    /**
     * Gibt die ID des Benutzers zurück
     * 
     * @return int|null Die eindeutige ID des Benutzers, oder null wenn noch nicht gesetzt
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Gibt die E-Mail-Adresse des Benutzers zurück
     * 
     * @return string|null Die E-Mail-Adresse oder null, wenn nicht gesetzt
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Setzt die E-Mail-Adresse des Benutzers
     * 
     * @param string $email Die zu setzende E-Mail-Adresse
     * @return $this Für Method Chaining
     */
    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * Gibt die Rollen des Benutzers zurück
     * 
     * Diese Methode wird vom Security-System verwendet, um die Berechtigungen
     * zu überprüfen. Jeder Benutzer erhält automatisch die Rolle 'ROLE_USER'.
     * 
     * @return array<int,string> Ein Array mit allen Rollen des Benutzers
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /**
     * Setzt die Rollen des Benutzers
     * 
     * @param array<int,string> $roles Array mit den zu setzenden Rollen
     * @return $this Für Method Chaining
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * Gibt das verschlüsselte Passwort des Benutzers zurück
     * 
     * Diese Methode wird vom Security-System während der Authentifizierung
     * verwendet.
     * 
     * @return string|null Das verschlüsselte Passwort
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Setzt das verschlüsselte Passwort des Benutzers
     * 
     * Hinweis: Das Passwort sollte vor dem Setzen durch einen
     * PasswordHasherInterface-Dienst gehasht werden.
     * 
     * @param string $password Das zu setzende (bereits verschlüsselte) Passwort
     * @return $this Für Method Chaining
     */
    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Löscht sensible Daten aus dem Benutzerobjekt
     * 
     * Diese Methode wird aufgerufen, wenn das Benutzerobjekt serialisiert wird,
     * um sicherzustellen, dass keine sensiblen Daten in der Session gespeichert werden.
     * 
     * @return void
     */
    public function eraseCredentials(): void
    {
        // Wenn temporäre, sensible Daten im Objekt gespeichert sind, hier löschen
    }

    /**
     * Gibt den eindeutigen Identifikator des Benutzers zurück
     * 
     * Diese Methode wird vom Security-System verwendet, um den Benutzer
     * zu identifizieren.
     * 
     * @return non-empty-string Die E-Mail-Adresse als Identifikator
     * @throws \RuntimeException Wenn keine gültige E-Mail-Adresse vorhanden ist
     */
    public function getUserIdentifier(): string
    {
        $email = trim($this->email ?? '');
        if ($email === '') {
            $this->translator?->trans('error.invalid_email') ?? 'error.invalid_email';
        }

        return $email;
    }
}
