<?php

use Nette\Application\UI;
use Nette\Security as NS;

class HomepagePresenter extends BasePresenter
{

  function startup()
  {
    parent::startup();
  }


	function getModel()
	{
		return $this->getService('model');
	}


  public function actionTwitterLogin()
  {
    $params = $this->getContext()->params;
    $session = $this->getSession('oauth');
    $oauth = new TwitterOAuth($params['twitterKey'], $params['twitterSecret']);

    $requestToken = $oauth->getRequestToken($this->link('//twitterLoginCallback'));

    $session->requestTokenKey = $requestToken['oauth_token'];
    $session->requestTokenSecret = $requestToken['oauth_token_secret'];

    $url = $oauth->getAuthorizeURL($requestToken['oauth_token']);
    $this->redirectUrl($url, 301);
  }


  public function actionTwitterLoginCallback()
  {
    if (!isset($this->params['oauth_verifier'])) {
      $this->flashMessage('Login unsuccessful');
      $this->redirect('default');
    }

    $params = $this->getContext()->params;
    $session = $this->getSession('oauth');

    if (!isset ($session->requestTokenKey)) {
      $this->flashMessage('Login error');
      $this->redirect('default');
    }

    $oauth = new TwitterOAuth($params['twitterKey'], $params['twitterSecret'], $session->requestTokenKey, $session->requestTokenSecret);
    unset($session->requestTokenKey, $session->requestTokenSecret);
    $info = $oauth->getAccessToken($this->params['oauth_verifier']);

    $loginData = (object) array(
      'type'              => 'twitter',
      'name'              => $info['screen_name'],
      'accessTokenKey'    => $info['oauth_token'],
      'accessTokenSecret' => $info['oauth_token_secret'],
    );

		try {
      $this->getUser()->setExpiration('+ 14 days', FALSE);
			$this->getUser()->login($loginData);
			$this->redirect('default');

		} catch (NS\AuthenticationException $e) {
      $this->flashMessage($e->getMessage());
		}

    $this->flashMessage('uživatel přidán');
    $this->redirect('default');
  }


	protected function createComponentLoginForm()
	{
		$form = new UI\Form;
		$form->addText('name', 'Jméno')
			->setRequired('Please provide a username.');

		$form->addPassword('pass', 'Heslo')
			->setRequired('Please provide a password.');

		$form->addSubmit('send', 'přihlásit se');

		$form->onSuccess[] = callback($this, 'loginFormSubmitted');
		return $form;
	}


	function loginFormSubmitted($form)
	{
		try {
			$values = $form->getValues();

      $loginData = (object) array(
        'type' => 'login',
        'name' => $values->name,
        'pass' => $values->pass,
      );

      $this->getUser()->setExpiration('+ 14 days', FALSE);
			$this->getUser()->login($loginData);
			$this->redirect('Homepage:');

		} catch (NS\AuthenticationException $e) {
			$form->addError($e->getMessage());
		}
	}


  protected function createComponentRegisterForm()
  {
		$form = new UI\Form;
		$form->addText('name', 'Jméno')
      ->setRequired('Please provide a username.')
      ->addRule(~UI\Form::PATTERN, 'Username cannot start with @', '@.*');

		$form->addPassword('pass', 'Heslo')
			->setRequired('Please provide a password.');

		$form->addPassword('passAgain', 'Potvrzení hesla')
      ->setRequired('Please provide a password once again.')
      ->addRule(UI\Form::EQUAL, 'Passwords did not match.', $form['pass']);

		$form->addSubmit('send', 'registrovat se');

		$form->onSuccess[] = callback($this, 'registerFormSubmitted');
		return $form;
  }


  function registerFormSubmitted($form)
  {
    try {
      $values = $form->values;
      unset($values->passAgain);

      $user = $this->model->registerUser($values);
    } catch (ModelException $e) {
      $form->addError($e->getMessage());
      return;
    }

    $this->flashMessage('Registrace proběhla úspěšně. Teď se můžete přihlásit.');
    $this->redirect('login');
  }


	public function actionLogout()
	{
		$this->getUser()->logout();
		$this->flashMessage('You have been signed out.');
		$this->redirect('default');
	}


  function actionDefault()
  {
    if ($this->user->loggedIn) {
      $id = $this->user->identity;
      // user is logged in but is not present in database, which indicates he was most likely deleted
      if (!$this->model->getUser($id->name, $id->type)) 
        $this->user->logout();
      else
        $this->redirect('user', $id->name, $id->type);
    }
  }


  function createComponentSeriesForm()
  {
		$form = new UI\Form;
    $form->addText('count', 'počet')
      ->setType('number')
      ->setRequired()
      ->addRule(UI\Form::RANGE, 'ale ale ale', array(1, 9001))
      ->setDefaultValue(1);
    $form->addSubmit('add', 'potvrdit');
    $form->onSuccess[] = callback($this, 'addSeries');
    return $form;
  }


  function createComponentChangeStartDate()
  {
		$form = new UI\Form;
    $form->addText('date', 'začátek')
      ->setType('date')
      ->setRequired()
      ->addRule(UI\Form::PATTERN, 'neplatné datum', '^\d\d\d\d-\d\d-\d\d$');
    $form->addSubmit('change', 'potvrdit');
    $self = $this;
    $form->onSuccess[] = function ($form) use($self) {
      $date = Nette\DateTime::from($form->values->date);
      try {
        $self->model->changeStartDate($self->user, $date);
      } catch (ModelException $e) {
        $self->flashMessage($e->getMessage());
      }
      $self->redirect('this');
    };
    return $form;
  }


  function addSeries(UI\Form $form)
  {
    $count = $form->values->count;
    $this->model->addSeries($this->user, $count);
    if ($count == 1) {
      $this->flashMessage("Jenom jeden klik? S takovouhle bysme ten socialismus nevybudovali.");
    } elseif ($count < 5) {
      $this->flashMessage("Jenom $count kliky? S takovouhle bysme ten socialismus nevybudovali.");
    } elseif ($count == 5) {
      $this->flashMessage("Jenom $count kliků? S takovouhle bysme ten socialismus nevybudovali.");
    } elseif ($count >= 300) {
      $this->flashMessage("ಠ_ಠ");
    } elseif ($count >= 150) {
      $this->flashMessage("No ty vole! ");
    } elseif ($count >= 100) {
      $this->flashMessage("Mission accomplished.");
    }
    $this->redirect('default');
  }


  function actionList($complete = false)
  {
    $this->template->users = $this->model->getUsers($complete);
  }


  function actionStats()
  {
    $this->template->stats = $this->model->getStats();
  }


  function actionUser($name, $type)
  {
    $progress = $this->model->getUserProgress($name, $type);
    if (!$progress) {
      $this->flashMessage('No such user');
      $this->redirect('default');
    }
    $this->template->userProgress = $progress;
  }


  function handleDeleteSeries($id)
  {
    if ($this->user->loggedIn) {
      try {
        $this->model->deleteSeries($this->user, $id);
      } catch (ModelException $e) {
        $this->flashMessage($e->getMessage());
      }
    }
    $this->redirect('this');
  }
}
