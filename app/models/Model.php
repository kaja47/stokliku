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


  /** return list of users and their maximum */
  function getUsers($completeList = false)
  {
    $res = $this->db->query('
      select u.*,
        ifnull(max(count), 0) max,
        ifnull(sum(count), 0) sum,
        to_days(now()) - to_days(u.started)     days,
        to_days(u.started) + ? - to_days(now()) daysLeft,
        to_days(now()) - to_days(max(s.day))    daysUnactive,
        count(distinct to_days(s.day))          activeDays

      from users u
      left join series s on u.id = s.userId
      where s.deleted = false and u.deleted = false
      group by u.id
      order by max desc, u.id
    ', $this->duration);

    $users = (object) array(
      'normal' => array(),
      'super' => array(),
      'zero' => array(),
      'unactive' => array(),
    );

    if ($completeList)
      foreach ($res as $u)
        $users->normal[] = $u;
    else
      foreach ($res as $u) {
        if ($u->max == 0)               $users->zero[]     = $u;
        elseif ($u->daysUnactive >= 14) $users->unactive[] = $u;
        elseif ($u->max > 140)          $users->super[]    = $u;
        else                            $users->normal[]   = $u;
      }

    $users->normalSum = array_sum(array_map(function ($u) { return $u->sum; }, $users->normal));
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

    foreach ($res as $k => $r) { $r->dayActiveUserIds = explode(',', $r->dayActiveUserIds); }

    foreach ($res as $k => $r) {
      $getActiveUsers = function ($res, $daysBack, $k) {
        $start = $k - $daysBack + 1;
        $pastDays = array_slice($res, max($start, 0), $daysBack + min($start, 0));
        $pastDays = array_map(function ($a) { return $a->dayActiveUserIds; }, $pastDays);
        return count(array_unique(call_user_func_array('array_merge', $pastDays)));
      };
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


  function addSeries($user, $count)
  {
    $this->db->exec('insert into series', array(
      'userId' => $user->identity->id,
      'count' => $count,
    ));
  }



  function deleteSeries($user, $seriesId)
  {
    $series = $this->db->table('series')->where('id', $seriesId)->fetch();

    if ($series->userId != $user->id)
      throw new ModelException("Nemůžete smazat cizí sérii kliků.");

    $this->db->exec('update series set deleted = true where id = ?', $seriesId);
  }


}
