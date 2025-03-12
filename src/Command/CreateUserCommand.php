<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Kommando zur Erstellung eines neuen Benutzers über die Kommandozeile
 *
 * Dieses Kommando ermöglicht die interaktive Erstellung eines neuen Benutzers
 * mit E-Mail und Passwort über die Symfony-Konsole.
 */
#[AsCommand(
    name: 'app:create-user',
    description: 'Erstellt einen neuen Benutzer',
)]
class CreateUserCommand extends Command
{
    /**
     * Konstruktor für das CreateUserCommand
     *
     * @param EntityManagerInterface $entityManager Doctrine Entity Manager für Datenbankoperationen
     * @param UserPasswordHasherInterface $passwordHasher Service zum Hashen von Benutzerpasswörtern
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    /**
     * Führt das Kommando aus
     *
     * Diese Methode:
     * - Fragt interaktiv nach E-Mail und Passwort
     * - Validiert die Eingaben
     * - Erstellt einen neuen Benutzer
     * - Speichert den Benutzer in der Datenbank
     *
     * @param InputInterface $input Interface für die Kommandozeileneingabe
     * @param OutputInterface $output Interface für die Kommandozeilenausgabe
     * @return int Erfolgsstatus des Kommandos (0 = erfolgreich, 1 = fehlgeschlagen)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $email = $io->ask('Email');
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Ungültige Email-Adresse');
            return Command::FAILURE;
        }
        
        $password = $io->askHidden('Passwort');
        if (!is_string($password) || strlen($password) < 6) {
            $io->error('Passwort muss mindestens 6 Zeichen lang sein');
            return Command::FAILURE;
        }
        
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setRoles(['ROLE_USER']);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        $io->success('Benutzer erfolgreich erstellt');
        
        return Command::SUCCESS;
    }
}