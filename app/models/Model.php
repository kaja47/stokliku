<?php


class ModelException extends Exception {}

class Model extends Nette\Object
{
	/** @var Nette\Database\Connection */
  private $db;

  private $goal = 100;
  private $duration = 42;


  function __construct(Nette\Database\Connection $connection)
  {
    $this->db = $connection;
  }


	static function calculateHash($password)
	{
    return md5($password);
	}


  function loginTwitterUser($data)
  {
    $user = $this->getUser($data->name, 'twitter');
    if (!$user) {
      $this->addUser($data);
      $user = $this->getUser($data->name, 'twitter');
    }
    return $user->toArray();
  }


  function registerUser($data) // name, pass
  {
    $data->type = 'login';

    if ($this->getUser($data->name, null))
      throw new ModelException("User $data->name already exists");

    $data->pass = self::calculateHash($data->pass);

    $this->addUser($data);
    return $this->getUser($data->name, 'login')->toArray();
  }


  private function addUser($data) {
    $data->registered = $data->started = new Nette\DateTime();
    $this->db->exec('insert into users', (array) $data);
  }


  function getUser($name, $type = null) {
    $q = $this->db->table('users')->where('name = ?', $name);
    if ($type !== null) $q = $q->where('type', $type);
    return $q->fetch();
  }



  function changeStartDate($user, $date)
  {
    $regdate = strtotime($this->db->table('users')->where('id', $user->id)->fetch()->registered);
    $dateTS = $date->getTimestamp();

    $firstSeries = $this->db->query('select date(min(day)) d from series where userId = ?', $user->id)->fetch()->d;
    $firstSeries = $firstSeries === NULL ? NULL : strtotime($firstSeries);

    if (time() > $regdate + Nette\DateTime::WEEK) // change allowed
      throw new ModelException('Změna nepovolena');

    if ($dateTS < $regdate - Nette\DateTime::MONTH) // date changed by one month at most
      throw new ModelException('Datum nelze změnit tak moc');

    if ($dateTS > $firstSeries)
      throw new ModelException('Tato změna není dovolena.');

    $this->db->exec('update users set started = ? where id = ?', $date, $user->id);
  }


  /**
   * result format:
   * [{
   *  day
   *  date
   *  exercises = [
   *    1 => {
   *      series = [ id => count, ...]
   *      sum
   *      max
   *      cumulativeSum
   *      cumulativeMax
   *    },
   *    2 => ...
   *  ]
   * }, { skipped = 47 }
   */
  function getUserProgress($userName, $type)
  {
    $user = $this->db->table('users')->where('name', $userName)->where('type', $type)->fetch();
    if (!$user)
      return NULL;

    $res = $this->db->query('
      select
        to_days(day) day,
        date(day) date,
        group_concat(concat(id, ":", exerciseId, ":", count) order by id) series
      from series
      where userId = ?
        and deleted = false
      group by date(day) desc
    ', $user->id)->fetchAll();

    $_days = $this->db->query('select to_days(now()) today, to_days(?) started', $user->started)->fetch();
    $startDay = $_days->started;
    $today    = $_days->today;
    $endDay   = $startDay + $this->duration;

    $progress = array();

    $lastDay = $today;
    $lastDate = new Nette\DateTime();
    $first = true;
    foreach ($res as $r) {
      if ($first) {
        if ($lastDay > $r->day) $progress[] = (object) array(
          'day'       => $lastDay,
          'date'      => $lastDate->format('Y-m-d'),
          'exercises' => array(),
          'today'     => true,
        );
        $first = false;
      }

      // insert skipped days
      if ($lastDay !== null) {
        $skippedDays = $lastDay - $r->day - 1; // -1 causes that today is never added as skipped day
        if ($skippedDays > 1) {
          $progress[] = (object) array(
            'skipped' => $skippedDays,
          );
        } else {
          for ($i = 1; $i <= $skippedDays; $i++) {
            $lastDate = clone $lastDate; // there is cloned base lastDate or lastDate from which one day was substracted
            $progress[] = (object) array(
              'day'    => $lastDay - $i,
              'date'   => $lastDate->modify('- 1 days')->format('Y-m-d'),
              'exercises' => array(),
            );
          }
        }
      }

      $exercises = array();
      foreach (explode(',', $r->series) as $ex) {
        list($id, $exerciseId, $count) = explode(':', $ex);
        if (!isset($exercises[$exerciseId])) {
          $exercises[$exerciseId] = (object) array(
            'series' => array(),
          );
        }

        $exercises[$exerciseId]->series[$id] = $count;
      }
      ksort($exercises);

      $progress[] = (object) array(
        'day'       => $r->day,
        'date'      => $r->date,
        'exercises' => $exercises,
      );

      $lastDay = $r->day;
      $lastDate = new Nette\DateTime($r->date);
    }

    // do aggregations
    $sumTotal = $currentMax = $cumulativeMax = $cumulativeDaySumMax = array(1 => 0, 2 => 0); // exerciseId => count
    $progress = array_reverse($progress);
    foreach ($progress as $row) {
      if (isset($row->skipped))
        continue;

      foreach ($row->exercises as $exId => $ex) {
        $ex->sum = array_sum($ex->series);
        $ex->max = $ex->series ? max($ex->series) : 0;

        $cumulativeDaySumMax[$exId] = max($cumulativeDaySumMax[$exId], $ex->sum);
        $ex->sumRecord = $ex->sum >= $cumulativeDaySumMax[$exId];

        $ex->cumulativeMax = $currentMax[$exId] = max($currentMax[$exId], $ex->max);
        $ex->cumulativeSum = $cumulativeMax[$exId] = ($cumulativeMax[$exId] + $ex->sum);
        $sumTotal[$exId] += $ex->sum;
      }
    }
    $progress = array_reverse($progress);

    $user->progress = $progress;
    $user->sumTotal = $sumTotal; // celkem kliků
    $user->startDay = $startDay;
    $user->endDay   = $endDay;
    $user->today    = $today;
    $user->remaining = $progress ? $endDay - reset($progress)->day : null;

    return $user;
  }


  /** return list of users and their maximum */
  function getUsers($completeList = false)
  {
    $userRes = $this->db->query('
      select 
        u.id, u.name, u.registered, u.started, u.type,
        to_days(now()) - to_days(u.started)     days,
        to_days(u.started) + ? - to_days(now()) daysLeft
      from users u
      where u.deleted = false
    ', $this->duration);

    $exercisesRes = $this->db->query('
      select
        userId, exerciseId,
        ifnull(max(count), 0) max,
        ifnull(sum(count), 0) sum,
        round(ifnull(sum(count) / (datediff(max(day), min(day))+1), 0), 1) avg,
        to_days(now()) - to_days(max(s.day))    daysUnactive,
        count(distinct to_days(s.day))          activeDays,
        max( ((to_days(now()) - to_days(s.day)) < 28) * count ) lastFourWeeks
      from series s
      where deleted = false
      group by userId, exerciseId
    ');

    $exercises = array();
    foreach($exercisesRes as $ex)
      $exercises[$ex->userId][$ex->exerciseId] = $ex;

    $us = $userRes->fetchAll();
    foreach ($us as $u) {
      $u->exercises = isset($exercises[$u->id]) ? $exercises[$u->id] : array();
      $u->daysUnactive = $u->exercises ? min(array_map(function ($e) { return $e->daysUnactive; }, $u->exercises)) : null;
    }

    $users = (object) array(
      'normal' => array(),
      'super' => array(),
      'zero' => array(),
      'unactive' => array(),
    );

    if ($completeList)
      foreach ($us as $u)
        $users->normal[] = $u;
    else
      foreach ($us as $u) {
        $exs = $u->exercises;
        $hasZero = true;
        foreach ($exs as $e) if ($e->max > 0) $hasZero = false;

        if ($hasZero)  
          $users->zero[] = $u;
        elseif ($u->daysUnactive >= 14)
          $users->unactive[] = $u;
        elseif (isset($exs[1]) && $exs[1]->max > 150)
          $users->super[] = $u;
        else
          $users->normal[] = $u;
      }

    $users->normalSum = array();
    foreach ($users->normal as $u)
      foreach ($u->exercises as $exId => $ex)
        @$users->normalSum[$exId] += $ex->sum; // @ intentionally

    return $users;
  }


  function getStats()
  {
    $res = $this->db->query('
      select
        date(day) date,
        to_days(day) day,
        count(distinct userId) dayActiveUsers,
        count(*)   daySeries,
        sum(count) daySum,
        group_concat(distinct userId order by userId) dayActiveUserIds
      from series s
      group by date(day)
    ')->fetchAll();

    $getActiveUsers = function ($res, $daysBack, $k) {
      $start = $k - $daysBack + 1;
      $pastDays = array_slice($res, max($start, 0), $daysBack + min($start, 0));
      $pastDays = array_map(function ($a) { return $a->dayActiveUserIds; }, $pastDays);
      return count(array_unique(call_user_func_array('array_merge', $pastDays)));
    };

    foreach ($res as $k => $r) {
      // tento krok se může provádět současně s tím následujícím, jenom proto, že funkce `getActiveUsers` potřebuje
      // vlastnost `dayActiveUserIds` nastavenou u tohoto a předchozích elementů
      $r->dayActiveUserIds = explode(',', $r->dayActiveUserIds);

      $r->activeUsers = array(
         3 => $getActiveUsers($res,  3, $k),
         7 => $getActiveUsers($res,  7, $k),
        14 => $getActiveUsers($res, 14, $k),
      );
    }

    foreach ($res as $k => $r) { 
      unset($r->dayActiveUserIds);
    }

    $lastDay = reset($res)->day;
    $data = array();
    foreach ($res as $k => $r) {
      // insert skipped days
      $skippedDays = $r->day - $lastDay - 1;
      if ($skippedDays > 0)
        $data[] = (object) array('skipped' => $skippedDays);

      $data[] = $r;
      $lastDay = $r->day;
    }

    return $data;
  }


  function addSeries($user, $count, $exerciseId)
  {
    $this->db->exec('insert into series', array(
      'userId'     => $user->identity->id,
      'count'      => $count,
      'exerciseId' => $exerciseId,
    ));
  }



  function deleteSeries($user, $seriesId)
  {
    $series = $this->db->table('series')->where('id', $seriesId)->fetch();

    if ($series->userId != $user->id)
      throw new ModelException("Nemůžete smazat cizí sérii kliků.");

    $this->db->exec('update series set deleted = true where id = ?', $seriesId);
  }


  function getExcercises()
  {
    return $this->db->table('exercise')->fetchPairs('id', 'title');
  }


}
