<?php

require('../vendor/autoload.php');

$app = new Silex\Application();
$app['debug'] = true;

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

// Our web handlers

$app->get('/', function() use($app) {
  $app['monolog']->addDebug('logging output.');
  return $app['twig']->render('index.twig');
});


//START MY CODE HERE
$app->get('/register/', function() use($app) {
  $app['monolog']->addDebug('logging output.');
  $output='';
  $output.='<form action="../submitUser" method="post">';
  $output.='Username: <input type="text" name="username"><br>';
  $output.='Password: <input type="password" name="password"><br>';
  $output.='Confirm Password:<input type="password" name="password2"><br>';
  $output.='Security Question: "What was your first dog's name?" <br>';
  $output.='Answer: <input type="text" name="securityAnswer"> <br>';
  $output.='<input type="submit">';
  $output.='</form>';
  return $output;

  //return $app['twig']->render('reg.twig');
});

//DB Tutorial
$dbopts = parse_url(getenv('DATABASE_URL'));
$app->register(new Csanquer\Silex\PdoServiceProvider\Provider\PDOServiceProvider('pdo'),
               array(
                'pdo.server' => array(
                   'driver'   => 'pgsql',
                   'user' => $dbopts["user"],
                   'password' => $dbopts["pass"],
                   'host' => $dbopts["host"],
                   'port' => $dbopts["port"],
                   'dbname' => ltrim($dbopts["path"],'/')
                   )
               )
);

//Add new handler to query database
$app->get('/db/', function() use($app) {
  $st = $app['pdo']->prepare('SELECT * FROM test_table');
  $st->execute();

  $names = array();
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $app['monolog']->addDebug('Row ' . $row['name']);
    $names[] = $row;
  }

  return $app['twig']->render('database.twig', array(
    'names' => $names
  ));
});

//Add new handler to insert into database
$app->post('/submitUser/', function() use($app) {
  $st = $app['pdo']->prepare('INSERT INTO user_table values (?,?,\'blablabla\',\'blablabla\') ');
  $st->bindValue(1, $username, PDO::PARAM_STR);
  $st->bindValue(2, $password, PDO::PARAM_STR);
  $st->execute();
  return $app['twig']->render('index.twig');
});

// END MY CODE HERE
$app->run();
