<?php

namespace App\Controller;

use App\Entity\Registration;
use App\Entity\SportMatch;
use App\Entity\Tournament;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;
use OpenApi\Annotations as OA;

#[Route('/api/')]
class UserController extends AbstractController
{
    /**
     *  @OA\Response(
     *      response=200,
     *      @Model(type=User::class, groups={"getPlayers"})
     *  )
     *
     * @OA\Tag(name="rewards")
     * @Security(name="Bearer")
     */
    #[Route('register', name: 'register', methods: ['POST'])]
    public function register(Request $request, UserPasswordHasherInterface $passwordHasher, SerializerInterface $serializer,
                             ValidatorInterface $validator, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $requiredFields = ['firstName', 'lastName', 'username', 'emailAddress', 'password'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, "Missing required field: $field");
            }
        }

        $user = new User();
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setUsername($data['username']);
        $user->setEmailAddress($data['emailAddress']);
        $user->setPassword($data['password']);
        $user->setRoles(['ROLE_USER']);
        $user->setStatus('active');


        $errors = $validator->validate($user);
        if (count($errors) > 0) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, $errors[0]->getMessage());
        }

        $user->setPassword($passwordHasher->hashPassword($user, $user->getPassword()));

        $em->persist($user);
        $em->flush();
        $jsonUser = $serializer->serialize($user, 'json', ['groups' => 'getPlayers']);
        $response = [
            'message' => 'User created successfully',
            'status' => Response::HTTP_CREATED,
            'user' => json_decode($jsonUser, true)
        ];
        return new JsonResponse($response, Response::HTTP_CREATED);
    }

    /**
     * @param UserRepository $userRepository // UserRepository object
     * @param SerializerInterface $serializer // SerializerInterface object
     * @return JsonResponse // JsonResponse object
     */
    #[Route('players', name: 'allPlayers', methods: ['GET'])]
    public function getAllUsers(UserRepository $userRepository, SerializerInterface $serializer): JsonResponse
    {
        $userList = $userRepository->findAll();
        $jsonUserList = $serializer->serialize($userList, 'json', ['groups' => 'getPlayers']);

        if (count($userList) === 0) {
            $response = [
                'message' => 'No users found',
                'status' => Response::HTTP_NOT_FOUND
            ];
            return new JsonResponse($response, Response::HTTP_NOT_FOUND);
        }

        $response = [
            'message' => 'List of all users',
            'number_of_users' => count($userList),
            'status' => Response::HTTP_OK,
            'users' => json_decode($jsonUserList, true)
        ];
        return new JsonResponse($response, Response::HTTP_OK);

    }

    /**
     * @param SerializerInterface $serializer // SerializerInterface object
     * @return JsonResponse // JsonResponse object
     */
    #[Route('players/{id}', name: 'playerById', methods: ['GET'])]
    public function getUserById(int $id,  SerializerInterface $serializer, EntityManagerInterface $em): JsonResponse
    {
        $user = $em->getRepository(User::class)->find($id);
        if(!$user) {
            throw new HttpException(Response::HTTP_NOT_FOUND, "Player not found");
        }

        $jsonUser = $serializer->serialize($user, 'json', ['groups' => 'getPlayers']);

        $response = [
            'message' => 'User found',
            'status' => Response::HTTP_OK,
            'user' => json_decode($jsonUser, true)
        ];
        return new JsonResponse($response, Response::HTTP_OK);
    }

    /**
     * @param Request $request // Request object
     * @param SerializerInterface $serializer // SerializerInterface object
     * @param ValidatorInterface $validator // ValidatorInterface object
     * @param UserPasswordHasherInterface $passwordHasher // UserPasswordHasherInterface object
     * @param EntityManagerInterface $em // EntityManagerInterface object
     * @return JsonResponse // JsonResponse object
     */
    #[Route('players/{id}', name: 'updatePlayer', methods: ['PUT'])]
    public function updateUser(int $id, Request $request, SerializerInterface $serializer, ValidatorInterface $validator,
                               UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): JsonResponse
    {
        $user = $em->getRepository(User::class)->find($id);
        if (!$user) {
            throw new HttpException(Response::HTTP_NOT_FOUND, "Player not found");
        }

        $currentUser = $this->getUser();
        if ($currentUser->getId() !== $user->getId()) {
            if (!in_array('ROLE_ADMIN', $currentUser->getRoles())) {
                throw new HttpException(Response::HTTP_FORBIDDEN, "You cannot update another user");
            }
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                throw new HttpException(Response::HTTP_FORBIDDEN, "Admins cannot be modified by other admins");
            }
        }

        $content = $request->getContent();
        $updatedUser = $serializer->deserialize($content, User::class, 'json', ['object_to_populate' => $user]);

        if ($currentUser->getId() === $user->getId()) {
            $contentData = json_decode($content, true);
            if (isset($contentData['roles']) && $contentData['roles'] !== $user->getRoles()) {
                throw new HttpException(Response::HTTP_FORBIDDEN, "You cannot change your own role");
            }

            if (isset($contentData['status']) && $contentData['status'] !== $user->getStatus()) {
                throw new HttpException(Response::HTTP_FORBIDDEN, "You cannot change your own status");
            }
        }

        $errors = $validator->validate($updatedUser);
        if (count($errors) > 0) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, $errors[0]->getMessage());
        }

        $contentData = json_decode($request->getContent(), true);

        if (!isset($contentData['password'])) {
            $context = ['object_to_populate' => $user, 'default_constructor_arguments' => ['password' => $user->getPassword()]];
        } else {
            $context = ['object_to_populate' => $user];
        }

        $updatedUser = $serializer->deserialize($request->getContent(), User::class, 'json', $context);

        if (!empty($updatedUser->getPassword()) && isset($contentData['password'])) {
            $updatedUser->setPassword($passwordHasher->hashPassword($updatedUser, $updatedUser->getPassword()));
        }

        $em->persist($updatedUser);
        $em->flush();

        $response = [
            'message' => 'User updated successfully',
            'status' => Response::HTTP_OK
        ];
        return new JsonResponse($response, Response::HTTP_OK);
    }

    /**
     * @param EntityManagerInterface $em // EntityManagerInterface object
     * @return JsonResponse // JsonResponse object
     */
    #[Route('players/{id}', name: 'deletePlayer', methods: ['DELETE'])]
    public function deleteUser(int $id, EntityManagerInterface $em): JsonResponse
    {
        $user = $em->getRepository(User::class)->find($id);
        if(!$user) {
            throw new HttpException(Response::HTTP_NOT_FOUND, "Player not found");
        }

        $currentUser = $this->getUser();
        if ($currentUser->getId() !== $user->getId() && !in_array('ROLE_ADMIN', $currentUser->getRoles())) {
            throw new HttpException(Response::HTTP_FORBIDDEN, "You cannot delete another user");
        }

        if (in_array('ROLE_ADMIN', $currentUser->getRoles()) && in_array('ROLE_ADMIN', $user->getRoles())) {
            throw new HttpException(Response::HTTP_FORBIDDEN, "Admin cannot delete another admin");
        }

        $sportMatches = $em->getRepository(SportMatch::class)->findBy(['player1' => $user]);
        $sportMatches = array_merge($sportMatches, $em->getRepository(SportMatch::class)->findBy(['player2' => $user]));

        foreach ($sportMatches as $sportMatch) {
            $em->remove($sportMatch);
        }

        $registrations = $em->getRepository(Registration::class)->findBy(['player' => $user]);

        foreach ($registrations as $registration) {
            $em->remove($registration);
        }

        $tournaments = $em->getRepository(Tournament::class)->findBy(['organizer' => $user]);

        foreach ($tournaments as $tournament) {
            $tournamentSportMatches = $em->getRepository(SportMatch::class)->findBy(['tournament' => $tournament]);
            foreach ($tournamentSportMatches as $sportMatch) {
                $em->remove($sportMatch);
            }

            $tournamentRegistrations = $em->getRepository(Registration::class)->findBy(['tournament' => $tournament]);
            foreach ($tournamentRegistrations as $registration) {
                $em->remove($registration);
            }

            $em->remove($tournament);
        }

        $em->remove($user);
        $em->flush();
        $response = [
            'message' => 'User deleted successfully',
            'status' => Response::HTTP_OK
        ];
        return new JsonResponse($response, Response::HTTP_OK);
    }
}
