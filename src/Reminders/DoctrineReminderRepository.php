<?php

namespace Mitch\LaravelDoctrine\Reminders;

use Carbon\Carbon;
use Doctrine\ORM\EntityManagerInterface;
use Illuminate\Auth\Reminders\RemindableInterface;
use Illuminate\Auth\Reminders\ReminderRepositoryInterface;

class DoctrineReminderRepository implements ReminderRepositoryInterface {

    /**
     * Constructs the repository.
     *
     * @param EntityManagerInterface $entities
     * @param string                 $hashKey
     * @param int                    $expires
     */

    public function __construct(EntityManagerInterface $entities, $hashKey, $expires = 60)
    {
        $this->entities = $entities;
        $this->expires = $expires * 60;
        $this->hashKey = $hashKey;
    }

    /**
     * Create a new reminder record and token.
     *
     * @param  \Illuminate\Auth\Reminders\RemindableInterface $user
     * @return string
     */
    public function create(RemindableInterface $user)
    {
        $email = $user->getReminderEmail();

        $this->deleteExisting($user);

        // We will create a new, random token for the user so that we can e-mail them
        // a safe link to the password reset form. Then we will insert a record in
        // the database so that we can verify the token within the actual reset.
        $token = $this->createNewToken($user);

        $reminder = new PasswordReminder($email, $token);
        $this->entities->persist($reminder);
        $this->entities->flush();

        return $token;
    }

    /**
     * Create a new token for the user.
     *
     * @param  \Illuminate\Auth\Reminders\RemindableInterface  $user
     * @return string
     */
    protected function createNewToken(RemindableInterface $user)
    {
        $email = $user->getReminderEmail();

        $value = str_shuffle(sha1($email.spl_object_hash($this).microtime(true)));

        return hash_hmac('sha1', $value, $this->hashKey);
    }

    /**
     * Delete all existing reset tokens from the database.
     *
     * @param  \Illuminate\Auth\Reminders\RemindableInterface  $user
     * @return int
     */
    protected function deleteExisting(RemindableInterface $user)
    {
        return $this->makeDelete()
            ->where('o.email = :email')
            ->setParameter('email', $user->getReminderEmail())
            ->getQuery()
            ->execute();
    }

    /**
     * Determine if a reminder record exists and is valid.
     *
     * @param  \Illuminate\Auth\Reminders\RemindableInterface $user
     * @param  string                                         $token
     * @return bool
     */
    public function exists(RemindableInterface $user, $token)
    {
        $email = $user->getReminderEmail();

        $reminder = $this->makeSelect()
            ->where('o.email = :email')
            ->andWhere('o.token = :token')
            ->setParameter('email', $email)
            ->setParameter('token', $token)
            ->getQuery()
            ->getFirstResult();

        return !$this->reminderExpired($reminder);
    }

    /**
     * Determine if the reminder has expired.
     *
     * @param  PasswordReminder  $reminder
     * @return bool
     */
    protected function reminderExpired($reminder)
    {
        $createdPlusHour = strtotime($reminder->getCreatedAt()) + $this->expires;

        return $createdPlusHour < time();
    }

    /**
     * Delete a reminder record by token.
     *
     * @param  string $token
     * @return void
     */
    public function delete($token)
    {
        $this->makeDelete()
            ->where('o.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete expired reminders.
     *
     * @return void
     */
    public function deleteExpired()
    {
        $expired = Carbon::now()->subSeconds($this->expires);

        $this->makeDelete()
            ->where('o.createdAt < :expired')
            ->setParameter('expired', $expired)
            ->getQuery()
            ->execute();
    }

    protected function makeSelect()
    {
        return $this->entities->createQueryBuilder()
            ->select('o')
            ->from('Mitch\LaravelDoctrine\Reminders\PasswordReminder', 'o');
    }

    protected function makeDelete()
    {
        return $this->entities->createQueryBuilder()
            ->delete('Mitch\LaravelDoctrine\Reminders\PasswordReminder', 'o');
    }

}