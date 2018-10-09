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
  return $app['twig']->render('index.twig');
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
$app->get('/db/{table}', function($table) use($app) {
  $full_table_name = $table . '_table';
  $st = $app['pdo']->prepare('SELECT * FROM ' . $full_table_name);
  $st->execute();

  $data = array();
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    //$app['monolog']->addDebug('Row ' . $row['user_nm']);//$row['name'] for column
    $data[] = implode("," , $row);
  }

  return $app['twig']->render('database.twig', array(
    'data' => $data
  ));
});

//Add new handler to insert into database
$app->post('/register', function(Request $request) use($app) {
  
  $username = $request->get('username');
  $password = password_hash($request->get('password'), PASSWORD_DEFAULT);
  $securityAnswer = password_hash($request->get('securityAnswer'), PASSWORD_DEFAULT);  
  
  $st = $app['pdo']->prepare("INSERT INTO user_table (user_nm, password, sec_question, sec_answer) values (?,?,'What is the name of your best friend?', ?);");
  $st->bindValue(1, $username, PDO::PARAM_STR);
  $st->bindValue(2, $password, PDO::PARAM_STR);
  $st->bindValue(3, $securityAnswer, PDO::PARAM_STR);
  
  $app['monolog']->addDebug('Veryify ' . password_verify($request->get('password'), $password));
  $app['monolog']->addDebug('Bad Password ' . password_verify('not the password', $password));
  
  if($st->execute()){
      //INSERT worked
      return $app->redirect('../?success=true');
  }else{
      //INSERT failed
      return $app->redirect('../?success=fail');
  }   
});

$app->post('/inbox/send', function(Request $request) use($app) {
    
    $to = $request->get('to');
    $from = $request->get('from');
    $subject =  $request->get('subject');
    $message  = $request->get('message');
    $message_id = $from . time();
    
    $st = $app['pdo']->prepare("INSERT INTO message_table (message_id, to_id, from_id, subject, text) values (?,?,?,?,?);");
    $st->bindValue(1, $message_id, PDO::PARAM_STR);
    $st->bindValue(2, $to, PDO::PARAM_STR);
    $st->bindValue(3, $from, PDO::PARAM_STR);
    $st->bindValue(4, $subject, PDO::PARAM_STR);
    $st->bindValue(5, $message, PDO::PARAM_STR);
    
    if($st->execute()){
        //INSERT worked
        return $app->redirect('../inbox?success=true');
    }else{
        //INSERT failed
        return $app->redirect('../inbox?success=fail');
    }
});

$app->post('/login', function(Request $request) use($app) {
    //Get parameters from UI
    $username = $request->get('username');
    $password = $request->get('password');
    $app['monolog']->addDebug('Username: ' . $username . "; Password: " . $password);
    
    //Select user record from user_table
    $st = $app['pdo']->prepare('SELECT password from user_table WHERE user_nm=?;');
    $st->bindValue(1, $username, PDO::PARAM_STR);
    $st->execute();
    $app['monolog']->addDebug('Executed SELECT statement');
    
    //extract hashed password from table
    $hash='';
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $app['monolog']->addDebug('data: ' . $row['password']);
        $hash = $row['password'];
    }
    
    $app['monolog']->addDebug('$hash' . $hash);
    $app['monolog']->addDebug('password_verify($password, $hash)' . password_verify($password, $hash));
    
    if($hash !== '' && password_verify($password, $hash)){
        $app['monolog']->addDebug('USER IS VERIFIED');
        //TODO need to reset table for bad attempts
        updateBadAttempts($username, false);
        //TODO select messages from here now that we have authenticated and pass them to inbox
//         $data = array();
//         while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
//             //$app['monolog']->addDebug('Row ' . $row['user_nm']);//$row['name'] for column
//             $data[] = implode("," , $row);
//         }
        
//         return $app['twig']->render('database.twig', array(
//             'data' => $data
//         ));
        
        
        return $app->redirect('/inbox/');
    }else{
        $app['monolog']->addDebug('USER IS DENIED');
        updateBadAttempts($username, true);
        //TODO need to update table for bad attempts
        return $app->redirect('../?success=false');
    }
    
    //Utility Functions (Inside Verify)
    function updateBadAttempts($user, $bad_attempt){
        //TODO reset timer to prevent logins for a day
        if($bad_attempt){
            $st = $app['pdo']->prepare('UPDATE user_table SET bad_attempts = bad_attempts +1 WHERE user_nm=?;');
            $st->bindValue(1, $user, PDO::PARAM_STR);
            $st->execute();
            $app['monolog']->addDebug('Incremented bad attempts');
        }else{
            $st = $app['pdo']->prepare('UPDATE user_table SET bad_attempts = 0 WHERE user_nm=?;');
            $st->bindValue(1, $user, PDO::PARAM_STR);
            $st->execute();
            $app['monolog']->addDebug('Reset bad attempts to 0');
        }
    }
    
    
    
});

$app->post('/reset', function(Request $request) use($app) {
    $username = $request->get('username');
    $secAnswer = $request->get('securityAnswer');
    return $app->redirect('/');
    
});
    
$app->get('/inbox/', function() use($app) {
    return $app['twig']->render('message.twig');
});

$app->get('/inbox/login', function() use($app) {
    return $app->redirect('../../');
});

// END MY CODE HERE
$app->run();
