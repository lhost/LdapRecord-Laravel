# Scopes

Authentication scopes allow you to restrict which LDAP users are allowed to authenticate.

If you're familiar with Laravel's [Query Scopes](https://laravel.com/docs/5.4/eloquent#query-scopes),
then these will feel very similar.

## Creating a Scope

To create a scope, it must implement the interface `Adldap\Laravel\Scopes\ScopeInterface`.

For this example, we'll create a folder inside our `app` directory containing our scope named `Scopes`.

With this scope, we want to only allow members of an Active Directory group named `Accouting`:


```php
namespace App\Scopes;

use Adldap\Query\Builder;
use Adldap\Laravel\Scopes\ScopeInterface;

class AccountingScope implements ScopeInterface
{
    public function apply(Builder $query)
    {
        // The distinguished name of our LDAP group.
        $accounting = 'cn=Accounting,ou=Groups,dc=acme,dc=org';
        
        $query->whereMemeberOf($accouning);
    }
}
```

## Implementing a Scope

Now that we've created our scope (`app/Scopes/AccountingScope.php`), we can insert it into our `config/adldap_auth.php` file:

```php
'scopes' => [

    // Only allows users with a user principal name to authenticate.

    Adldap\Laravel\Scopes\UpnScope::class,
    
    // Only allow members of accounting to login.
    
    App\Scopes\AccountingScope::class,

],
```

Once you've inserted your scope into the configuration file, you will now only be able
to authenticate with users that are a member of the `Accounting` group.