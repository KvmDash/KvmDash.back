<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\HttpFoundation\JsonResponse;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;

use App\Entity\User;
use App\Dto\LoginResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

#[ApiResource(
    operations: [
        new Post(
            name: 'api_login',
            uriTemplate: '/login',
            controller: self::class.'::login',
            output: LoginResponse::class,
            read: false
        )
    ]
)]


class LoginController extends AbstractController
{
    public function __construct(
        private readonly TranslatorInterface $translator
    ){}


    public function login(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json([
                'code' => 401,
                'message' => $this->translator->trans('error.invalid_credentials')
            ], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'user'  => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
