<?php

/**
 * Base class for all application presenters.
 *
 * @author     John Doe
 * @package    MyApplication
 */
abstract class BasePresenter extends Nette\Application\UI\Presenter
{

	function beforeRender()
	{
		$this->template->registerHelper('userName', function($user) {
      if ($user->type === 'twitter')  return '@' . $user->name;
      if ($user->type === 'facebook') return '∮' . $user->name;
      if ($user->type === 'login')    return '' . $user->name;
      return $user->name;
		});
	}

}
