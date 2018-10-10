<?php

require('../vendor/autoload.php');

$app = new Silex\Application();
$app['debug'] = true;

//My ADD
use Symfony\Component\HttpFoundation\Request; 
use Symfony\Component\HttpFoundation\Session\Session;


//END My ADD

// Register the monolog logging service
$app->register(new Silex\Provider\MonologServiceProvider(), array(
  'monolog.logfile' => 'php://stderr',
));

// Register view rendering
$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/views',
));

$app->register(new Silex\Provider\SessionServiceProvider());

// Our web handlers

$app->get('/', function() use($app) {
  $app['session']->set('user', '');//reset authentication
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

$app->post('/send', function(Request $request) use($app) {
    //TODO figure out how to determine from field
    $ses = $app['session']->get('user');
    $app['monolog']->addDebug('session' . $ses);
    
    
    $to = $request->get('to');
    //$from = $request->get('from');
    $subject =  $request->get('subject');
    $message  = $request->get('message');
    $message_id = $from . time();
    
    $st = $app['pdo']->prepare("INSERT INTO message_table (to_id, from_id, subject, text) values (?,'acv',?,?);");
    $st->bindValue(1, $message_id, PDO::PARAM_STR);
    $st->bindValue(2, $to, PDO::PARAM_STR);
    $st->bindValue(3, $from, PDO::PARAM_STR);
    $st->bindValue(4, $subject, PDO::PARAM_STR);
    $st->bindValue(5, $message, PDO::PARAM_STR);
    
    if($st->execute()){
        //INSERT worked
        return $app['twig']->render('message.twig', array(
            'username'=> $username,
            'password'=> $password,
            'data' => $data
        ));
    }else{
        //INSERT failed
        return $app['twig']->render('message.twig', array(
            'username'=> $username,
            'password'=> $password,
            'data' => $data
        ));
    }
});

$app->post('/login', function(Request $request) use($app) {
    //TODO set session user after log in
    //TODO read this user from inbox (messages.twig page)
    //TODO remove sessio user after logging out
    //Get parameters from UI
    $username = $request->get('username');
    $password = $request->get('password');
    $app['monolog']->addDebug('Username: ' . $username . "; Password: " . $password);
    
    $app['monolog']->addDebug('SELECT password, bad_attempts, (age(last_login_tm)> INTERVAL \'5 hours\')as age FROM user_table WHERE user_nm=' . $username .';');
    
    //Select user record from user_table
    $st = $app['pdo']->prepare('SELECT password, bad_attempts, (age(last_login_tm)> INTERVAL \'5 hours\')as age FROM user_table WHERE user_nm=?;');
    $st->bindValue(1, $username, PDO::PARAM_STR);
    $st->execute();
    
    //extract hashed password from table
    $hash='';
    $bad_attempts=0;
    $last_login_tm='';
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $hash = $row['password'];
        $bad_attempts=$row['bad_attempts'];
        $last_login_tm=$row['age'];
    }
    
    $app['monolog']->addDebug('$hash=' . $hash);
    $app['monolog']->addDebug('$$bad_attempts=' . $bad_attempts);
    $app['monolog']->addDebug('$last_login_tm=' . $last_login_tm);
    $app['monolog']->addDebug('password_verify($password, $hash)' . password_verify($password, $hash));
    
    //PreValidate: Need to get a row in the database
    //     * (bad_attempts <= 3 OR logged in more than 5 hours ago)
    //Validate: Password hash matches hash in the database
    if($hash !== '' && ($badAttempts <= 3 || $last_login_tm) && password_verify($password, $hash)){
        $app['monolog']->addDebug('USER IS VERIFIED');
        $st = $app['pdo']->prepare('UPDATE user_table SET bad_attempts = 0, last_login_tm=CURRENT_TIMESTAMP WHERE user_nm=?;');
        $st->bindValue(1, $username, PDO::PARAM_STR);
        $st->execute();
        
        $app['monolog']->addDebug('Reset bad attempts to 0');
        //set the session ID
        $app['session']->set('user', $username);
        $app['monolog']->addDebug('Set session user to ' . $username);
        
        
     
        
        
        return $app->redirect('/inbox/');
    }else{
        //Invalid User
        $app['monolog']->addDebug('USER IS DENIED');
        $st = $app['pdo']->prepare('UPDATE user_table SET bad_attempts = bad_attempts +1, last_login_tm=CURRENT_TIMESTAMP WHERE user_nm=?;');
        $st->bindValue(1, $username, PDO::PARAM_STR);
        $st->execute();
        $app['monolog']->addDebug('Incremented bad attempts');

        return $app->redirect('../?success=false');
    }
    
});

$app->post('/reset', function(Request $request) use($app) {
    //TODO update db for this post
    $username = $request->get('username');
    $secAnswer = $request->get('securityAnswer');
    return $app->redirect('/');
    
});
    
$app->get('/inbox/', function() use($app) {
    
    $username = $app['session']->get('user');
    $app['monolog']->addDebug('Inbox user is' . $username);
    
    if($username == ''){
        return $app->redirect('../');//go back to login
    }else{
        $st = $app['pdo']->prepare('SELECT to_id, from_id, subject, text FROM message_table where to_id=? or from_id=?;');
        $st->bindValue(1, $username, PDO::PARAM_STR);
        $st->bindValue(2, $username, PDO::PARAM_STR);
        $st->execute();
        
        $data = array();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            //$app['monolog']->addDebug('Row ' . $row['user_nm']);//$row['name'] for column
            $data[] = $row;
            
        }

        
        return $app['twig']->render('message.twig', array(
            'username' => $username,
            'data' => $data
        ));
    }
});

// END MY CODE HERE
$app->run();
