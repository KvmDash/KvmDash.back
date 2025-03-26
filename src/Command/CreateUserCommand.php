<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CreateUserCommand - Console command to create new users in the system.
 * 
 * This command allows administrators to create new users with admin privileges
 * directly from the command line. It's useful for initial setup or when managing
 * users outside the application interface.
 * 
 * Usage:
 *   php bin/console app:create-user <email> <password>
 * 
 * Example:
 *   php bin/console app:create-user admin@example.com secure_password123
 * 
 * Notes:
 * - The created user will automatically have ROLE_ADMIN permissions
 * - The password will be properly hashed before storing in the database
 * - Email address should be valid to ensure proper functionality
 */
#[AsCommand(
    name: 'app:create-user',
    description: 'Creates a new user'
)]
class CreateUserCommand extends Command
{
    /**
     * Constructor for the CreateUserCommand.
     * 
     * @param EntityManagerInterface $entityManager For database operations
     * @param UserPasswordHasherInterface $passwordHasher For secure password hashing
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    /**
     * Configures the command by defining the input arguments needed.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'The email address of the user')
            ->addArgument('password', InputArgument::REQUIRED, 'The password of the user');
    }

    /**
     * Executes the command to create a new user with the provided details.
     * 
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     * @return int Command success or failure code
     * @throws \InvalidArgumentException If the email or password are not strings
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        if (!is_string($email)) {
            throw new \InvalidArgumentException('Email must be a string.');
        }

        if (!is_string($password)) {
            throw new \InvalidArgumentException('Password must be a string.');
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_ADMIN']);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln('User was created successfully!');

        return Command::SUCCESS;
    }
}
