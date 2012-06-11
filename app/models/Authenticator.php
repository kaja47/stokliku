<?php

use Nette\Security as NS;


class Authenticator extends Nette\Object implements NS\IAuthenticator
{
	private $model;


	public function __construct(Model $model)
	{
		$this->model = $model;
	}



	/**
	 * Performs an authentication
	 * @param  array
	 * @return Nette\Security\Identity
	 * @throws Nette\Security\AuthenticationException
	 */
	public function authenticate(array $credentials)
	{
    $credentials = reset($credentials);

    if ($credentials->type === 'twitter') {
      $user = $this->model->loginTwitterUser($credentials);
      return new NS\Identity($user['id'], 'user', $user);

    } else if ($credentials->type === 'login') { // type, name, pass
      $user = $this->model->getUser($credentials->name, 'login');

      if (!$user)
        throw new NS\AuthenticationException("User '$credentials->name' not found.", self::IDENTITY_NOT_FOUND);

      if ($user['pass'] !== Model::calculateHash($credentials->pass))
        throw new NS\AuthenticationException("Invalid password.", self::INVALID_CREDENTIAL);
      
      unset($user['pass']);
      return new NS\Identity($user['id'], 'user', $user);

    } else {
			throw new NS\AuthenticationException("Unsupported authentication method", self::IDENTITY_NOT_FOUND);
    }
	}

}
