<?php

namespace LdapRecord\Laravel;

use LdapRecord\Laravel\Events\Importing;
use LdapRecord\Laravel\Events\Synchronized;
use LdapRecord\Laravel\Events\Synchronizing;
use LdapRecord\Models\Model as LdapModel;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class LdapImporter
{
    /**
     * The Eloquent model to use for importing.
     *
     * @var string
     */
    protected $eloquentModel;

    /**
     * The import configuration.
     *
     * @var array
     */
    protected $config;

    /**
     * Constructor.
     *
     * @param string $eloquentModel
     * @param array  $config
     */
    public function __construct(string $eloquentModel, array $config)
    {
        $this->eloquentModel = $eloquentModel;
        $this->config = $config;
    }

    /**
     * Import / synchronize the LDAP object.
     *
     * @param LdapModel $user
     * @param array     $data
     *
     * @return EloquentModel
     */
    public function run(LdapModel $user, array $data = [])
    {
        $eloquent = $this->createOrFindEloquentModel($user);

        if (! $eloquent->exists) {
            event(new Importing($user, $eloquent));
        }

        event(new Synchronizing($user, $eloquent));

        $this->hydrate($user, $eloquent, $data);

        event(new Synchronized($user, $eloquent));

        return $eloquent;
    }

    /**
     * Hydrate the eloquent model with the LDAP object.
     *
     * @param LdapModel     $ldap
     * @param EloquentModel $model
     * @param array         $data
     *
     * @return void
     */
    protected function hydrate(LdapModel $ldap, $model, array $data = [])
    {
        /** @var EloquentHydrator $hydrator */
        $hydrator = transform($this->hydrator(), function ($hydrator) {
            return new $hydrator($this->config);
        });

        $hydrator->with($data)->hydrate($ldap, $model);
    }

    /**
     * Get the class name of the hydrator to use.
     *
     * @return string
     */
    protected function hydrator()
    {
        return EloquentHydrator::class;
    }

    /**
     * Retrieves an eloquent user by their GUID.
     *
     * @param LdapModel $ldap
     *
     * @return EloquentModel|null
     */
    protected function createOrFindEloquentModel(LdapModel $ldap)
    {
        $model = $this->createEloquentModel();

        $query = $model->newQuery();

        if ($query->getMacro('withTrashed')) {
            // If the withTrashed macro exists on our User model, then we must be
            // using soft deletes. We need to make sure we include these
            // results so we don't create duplicate user records.
            $query->withTrashed();
        }

        return $query
                ->where($model->getLdapGuidColumn(), '=', $ldap->getConvertedGuid())
                ->first() ?? $model->newInstance();
    }

    /**
     * Set the configuration for the importer to use.
     *
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the importer configuration.
     *
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get the name of the eloquent model.
     *
     * @return string
     */
    public function getEloquentModel()
    {
        return $this->eloquentModel;
    }

    /**
     * Get a new domain database model.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function createEloquentModel()
    {
        $class = '\\'.ltrim($this->eloquentModel, '\\');

        return new $class;
    }
}