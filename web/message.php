<?php

require('../vendor/autoload.php');

$app = new Silex\Application();
$app['debug'] = true;

//My ADD
use Symfony\Component\HttpFoundation\Request; 
//END My ADD

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
  return $app['twig']->render('message.twig');
});


//START MY CODE HERE
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
  $st = $app['pdo']->prepare('SELECT * FROM message_table');
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
$app->post('/send', function(Request $request) use($app) {
  
    return 'message sent test!';
//   $username = $request->get('username');
//   $password = $request->get('password');  
    
//   $st = $app['pdo']->prepare("INSERT INTO user_table (user_nm, password, sec_question, sec_answer) values (?,?,'semi123', 'semi1234');");
//   $st->bindValue(1, $username, PDO::PARAM_STR);
//   $st->bindValue(2, $password, PDO::PARAM_STR);
//   if($st->execute()){
//       //INSERT worked
//       return $app->redirect('../?success=true');
//   }else{
//       //INSERT failed
//       return $app->redirect('../?success=fail');
//   }   
});

$app->get('/login/', function() use($app) {
    return $app['twig']->render('index.twig');
    
});

// END MY CODE HERE
$app->run();
