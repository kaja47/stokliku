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


  function getUserProgress($userName, $type)
  {
    $user = $this->db->table('users')->where('name', $userName)->where('type', $type)->fetch();
    if (!$user)
      return NULL;

    $res = $this->db->query('
      select
        to_days(day) - to_days(?) + 1 day,
        group_concat(concat(id, ":", count) order by id) series
      from series
      where userId = ?
        and deleted = false
        and day > ?
      group by date(day)
    ', $user->started, $user->id, $user->started)->fetchAll();

    $nowDay = $this->db->query('select to_days(now()) - to_days(?) + 1 day', $user->started)->fetch()->day;

    // base dataset
    $progress = array();
    for ($i = 1; $i <= max($this->duration, $nowDay); $i++) {
      $date = new Nette\DateTime($user->started);
      $progress[$i] = (object) array(
        'series'      => array(),
        'projection'  => false,
        'date'        => $date->modify('+ ' . ($i - 1) . ' day'),
        'ago'         => $nowDay - $i,
        'today'       => $i == $nowDay,
        'lastDay'     => $i == $this->duration,
      );
    }

    // merge database data
    foreach ($res as $d) {
      $series = array();
      foreach (explode(',', $d->series) as $ex) {
        list($id, $count) = explode(':', $ex);
        $series[$id] = $count;
      }
      $progress[$d->day]->series = $series;
    }

    // do aggregations
    $sumTotal = $currentMax = $cumulativeMax = $cumulativeDaySumMax = 0;
    foreach ($progress as $day => $row) {
      $row->sum = array_sum($row->series);
      $row->max = $row->series ? max($row->series) : 0;

      $cumulativeDaySumMax = max($cumulativeDaySumMax, $row->sum);
      $row->sumRecord = $row->sum >= $cumulativeDaySumMax;

      $row->cumulativeMax = $currentMax = max($currentMax, $row->max);
      $row->cumulativeSum = $cumulativeMax = ($cumulativeMax + $row->sum);
      $sumTotal += $row->sum;
    }

    // do projection
    $startDay = ($progress[$nowDay]->sum === 0) ? $nowDay : ($nowDay + 1);
    $leftDays = $this->duration - $startDay + 1;
    if ($leftDays > 0) {
      $step = ((float) $this->goal - $currentMax) / $leftDays;
      if ($step >= 0) {
        $projection = $currentMax;
      } else { // user did more than $this->goal
        $projection = $this->goal;
        $step = 0;
      }
      for ($i = $startDay; $i <= $this->duration; $i++) {
        $projection += $step;
        $progress[$i]->series = array((int)$projection);
        $progress[$i]->projection = true;
      }
    }

    // když uživatel cvičí déle než 6 týdnů, data se rozdělí do několika záložek
    $p = (count($progress) > $this->duration) ? array_chunk($progress, 25, true) : array($progress);
    $user->progress = $p;
    $user->sumTotal = $sumTotal; // celkem kliků

    return $user;
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
  function getUserProgressNew($userName, $type)
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
    $res = $this->db->query('
      select 
        u.id, u.name, u.registered, u.started, u.type,
        s.exerciseId,
        ifnull(max(count), 0) max,
        ifnull(sum(count), 0) sum,
        to_days(now()) - to_days(u.started)     days,
        to_days(u.started) + ? - to_days(now()) daysLeft,
        to_days(now()) - to_days(max(s.day))    daysUnactive,
        count(distinct to_days(s.day))          activeDays

      from users u
      left join series s 
        on u.id = s.userId
      where s.deleted = false and u.deleted = false
      group by u.id, s.exerciseId
      order by max desc, u.id
    ', $this->duration);
    //dump($res->fetchAll()); exit;

    $us = array();
    foreach ($res as $r) {
      if (!isset($us[$r->id]))
        $us[$r->id] = $r;

      @$us[$r->id]->exercises[$r->exerciseId]->max = $r->max;
      @$us[$r->id]->exercises[$r->exerciseId]->sum = $r->sum;
      @$us[$r->id]->exercises[$r->exerciseId]->avg = $r->days > 0 ? round($r->sum / $r->days, 1) : 0;

      unset($us[$r->id]->sum);
      unset($us[$r->id]->max);
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
        if     (!isset($u->exercises[1]) || $u->exercises[1]->max == 0)  $users->zero[]     = $u;
        elseif ($u->daysUnactive >= 14)      $users->unactive[] = $u;
        elseif ($u->exercises[1]->max > 140) $users->super[]    = $u;
        else                                 $users->normal[]   = $u;
      }

    //$users->normalSum = array_sum(array_map(function ($u) { return $u->sum; }, $users->normal));
    return $users;
  }


  function getStats()
  {
    $res = $this->db->query('
      select
        date(day) date,
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

    return $res;
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
