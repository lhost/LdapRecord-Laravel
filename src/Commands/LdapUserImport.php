<?php

namespace LdapRecord\Laravel\Commands;

use LdapRecord\Laravel\Import;
use LdapRecord\Laravel\Events\Imported;
use LdapRecord\Laravel\Events\Importing;
use LdapRecord\Laravel\LdapUserRepository;
use LdapRecord\Models\Model as LdapRecord;
use LdapRecord\Models\Types\ActiveDirectory;
use LdapRecord\Laravel\Events\DeletedMissing;
use LdapRecord\Models\Attributes\AccountControl;
use Illuminate\Database\Eloquent\Model as Eloquent;

class LdapUserImport extends Import
{
    /**
     * The LDAP user repository to use for importing.
     *
     * @var LdapUserRepository
     */
    protected $repository;

    /**
     * Whether to restore soft-deleted database models if the object is enabled.
     *
     * @var bool
     */
    protected $restoreEnabledUsers = false;

    /**
     * Whether to soft-delete the database model if the object is disabled.
     *
     * @var bool
     */
    protected $trashDisabledUsers = false;

    /**
     * {@inheritDoc}
     */
    protected function registerDefaultCallbacks()
    {
        parent::registerDefaultCallbacks();

        $this->registerEventCallback('importing', function ($database, $object) {
            if (! $database->exists) {
                event(new Importing($object, $database));
            }
        });

        $this->registerEventCallback('imported', function ($database, $object) {
            if ($database->wasRecentlyCreated) {
                event(new Imported($object, $database));
            }

            if (! $object instanceof ActiveDirectory) {
                return;
            }

            if ($this->trashDisabledUsers) {
                $this->delete($database, $object);
            }

            if ($this->restoreEnabledUsers) {
                $this->restore($database, $object);
            }
        });

        $this->registerEventCallback('deleted.missing', function ($database, $ldap, $ids) {
            event(new DeletedMissing($ids, $ldap, $database));
        });
    }

    /**
     * Set the LDAP user repository to use for importing.
     *
     * @param LdapUserRepository $repository
     *
     * @return $this
     */
    public function setLdapUserRepository(LdapUserRepository $repository)
    {
        $this->repository = $repository;

        return $this;
    }

    /**
     * Enable restoring enabled users.
     *
     * @return $this
     */
    public function restoreEnabledUsers()
    {
        $this->restoreEnabledUsers = true;

        return $this;
    }

    /**
     * Enable trashing disabled users.
     *
     * @return $this
     */
    public function trashDisabledUsers()
    {
        $this->trashDisabledUsers = true;

        return $this;
    }

    /**
     * Load the import's objects from the LDAP repository.
     *
     * @param string|null $username
     *
     * @return \LdapRecord\Query\Collection
     */
    public function loadObjectsFromRepository($username = null)
    {
        $query = $this->applyLdapQueryConstraints(
            $this->repository->query()
        );

        if ($username) {
            $users = $query->getModel()->newCollection();

            return $this->objects = ($user = $query->findByAnr($username))
                ? $users->push($user)
                : $users;
        }

        return $this->objects = $query->paginate();
    }

    /**
     * Soft deletes the specified model if their LDAP account is disabled.
     *
     * @param Eloquent   $database
     * @param LdapRecord $object
     *
     * @return void
     */
    protected function delete(Eloquent $database, LdapRecord $object)
    {
        if (
            $this->isUsingSoftDeletes($database)
            && ! $database->trashed()
            && $this->userIsDisabled($object)
        ) {
            // If deleting is enabled, the model supports soft deletes, the model
            // isn't already deleted, and the LDAP user is disabled, we'll
            // go ahead and delete the users model.
            $database->delete();

            if ($this->logging) {
                logger()->info("Soft-deleted user [{$object->getRdn()}]. Their user account is disabled.");
            }
        }
    }

    /**
     * Restores soft-deleted models if their LDAP account is enabled.
     *
     * @param Eloquent   $database
     * @param LdapRecord $object
     *
     * @return void
     */
    protected function restore(Eloquent $database, LdapRecord $object)
    {
        if (
            $this->isUsingSoftDeletes($database)
            && $database->trashed()
            && $this->userIsEnabled($object)
        ) {
            // If the model has soft-deletes enabled, the model is
            // currently deleted, and the LDAP user account
            // is enabled, we'll restore the users model.
            $database->restore();

            if ($this->logging) {
                logger()->info("Restored user [{$object->getRdn()}]. Their user account has been re-enabled.");
            }
        }
    }

    /**
     * Determine whether the user is enabled.
     *
     * @param LdapRecord $object
     *
     * @return bool
     */
    protected function userIsEnabled(LdapRecord $object)
    {
        return $this->getUserAccountControl($object) === null ? false : ! $this->userIsDisabled($object);
    }

    /**
     * Determines whether the user is disabled.
     *
     * @param LdapRecord $object
     *
     * @return bool
     */
    protected function userIsDisabled(LdapRecord $object)
    {
        return ($this->getUserAccountControl($object) & AccountControl::ACCOUNTDISABLE) === AccountControl::ACCOUNTDISABLE;
    }

    /**
     * Get the user account control integer from the user.
     *
     * @param LdapRecord $object
     *
     * @return int|null
     */
    protected function getUserAccountControl(LdapRecord $object)
    {
        return $object->getFirstAttribute('userAccountControl');
    }
}
