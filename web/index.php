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

$app->get('/login', function() use($app) {
    $app['session']->set('user', '');//reset authentication
    return $app['twig']->render('login.twig');
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

$app->get('/dbdump', function() use($app) {
    return $app->redirect('/dbdump/user');
});

//Add new handler to query database
$app->get('/dbdump/{table}', function($table) use($app) {
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

$app->get('/register', function(Request $request) use($app) {
    return $app['twig']->render('reg.twig', array(
    ));
});

//Add new handler to insert into database
$app->post('/register/send', function(Request $request) use($app) {
  
  $username = $request->get('username');
  $password = password_hash($request->get('password'), PASSWORD_DEFAULT);
  $securityAnswer = password_hash($request->get('securityAnswer'), PASSWORD_DEFAULT);  
  $app['monolog']->addDebug('INSERT INTO user_table (user_nm, password, sec_answer) values ('. $username .',' . $password . ' , '. $securityAnswer  .  ');');
  
  $st = $app['pdo']->prepare("INSERT INTO user_table (user_nm, password, sec_answer) values (?,?,?);");
  $st->bindValue(1, $username, PDO::PARAM_STR);
  $st->bindValue(2, $password, PDO::PARAM_STR);
  $st->bindValue(3, $securityAnswer, PDO::PARAM_STR);
  
  if($st->execute()){
      //INSERT worked
      return $app->redirect('../../?message=Successfully%20Registered');
  }else{
      //INSERT failed
      return $app->redirect('../../?message=Registration%20Failed');
  }   
});

$app->get('/compose', function(Request $request) use($app) {
    $username = $app['session']->get('user');
    
    $users_st = $app['pdo']->prepare('SELECT user_nm FROM user_table;');
    $users_st->execute();
    $user_list = array();
    while ($row = $users_st->fetch(PDO::FETCH_ASSOC)) {
        $user_list[] = $row;
        
    }
    
    return $app['twig']->render('compose.twig', array(
        'username' => $username,
        'user_list' => $user_list
    ));
});

$app->post('/compose/send', function(Request $request) use($app) {
    $username = $app['session']->get('user');
    $app['monolog']->addDebug('session: ' . $username);
    
    if($username == ''){
        return $app->redirect('../');//go back to login
    }
    
    $to = $request->get('to');
    $from = $username;
    $subject =  $request->get('subject');
    $message  = $request->get('message');
    
    $app['monolog']->addDebug('SELECT public_key FROM user_table where user_nm=' . $to .';');
    $st = $app['pdo']->prepare("SELECT public_key FROM user_table where user_nm=?;");
    $st->bindValue(1, $to, PDO::PARAM_STR);
    $st->execute();
    
    $data = array();
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $app['monolog']->addDebug('Public Key ' . $row['public_key']);
        
        $secret_key = $row['public_key'];
        $secret_iv = $row['public_key'] . 'iv';
        $encrypt_method = "AES-256-CBC";
        
        
        $key = hash( 'sha256', $secret_key );
        $iv = substr( hash( 'sha256', $secret_iv ), 0, 16 );
        
        $app['monolog']->addDebug('base64_encode( openssl_encrypt(' . $message .', ' . $encrypt_method . ',' . $key . ', 0, ' . $iv . ' ) );');
        
        $output = base64_encode( openssl_encrypt( $message, $encrypt_method, $key, 0, $iv ) );
        $app['monolog']->addDebug('ENCCRYPT' . $output);
        
        //$output = openssl_decrypt( base64_decode( $output ), $encrypt_method, $key, 0, $iv );
        //$app['monolog']->addDebug('DECRYPT' . $output);
        
    }
    
    $app['monolog']->addDebug('INSERT INTO message_table (to_id, from_id, subject, text) values ('. $to . ',' . $from . ',' . $subject .',' . $message. ');');
    
    $st = $app['pdo']->prepare("INSERT INTO message_table (to_id, from_id, subject, text) values (?,?,?,?);");
    $st->bindValue(1, $to, PDO::PARAM_STR);
    $st->bindValue(2, $from, PDO::PARAM_STR);
    $st->bindValue(3, $subject, PDO::PARAM_STR);
    $st->bindValue(4, $output, PDO::PARAM_STR);
    
    if($st->execute()){
        return $app->redirect('../inbox/?message=Message%20Sent');
    }else{
        return $app->redirect('../inbox/?message=Message%20Failed-Please%20try%20again');
        
    }
});

$app->post('/login/send', function(Request $request) use($app) {
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
        
    $app['monolog']->addDebug('Variable values');
    $app['monolog']->addDebug('$hash=' . $hash);
    $app['monolog']->addDebug('$$bad_attempts=' . $bad_attempts);
    $app['monolog']->addDebug('$last_login_tm=' . $last_login_tm );
    $app['monolog']->addDebug('password_verify($password, $hash)' . password_verify($password, $hash));
    
    //PreValidate: Need to get a row in the database
    //     * (bad_attempts <= 3 OR logged in more than 5 hours ago)
    //Validate: Password hash matches hash in the database
    if($hash !== '' && ($bad_attempts <= 3 || $last_login_tm === true) && password_verify($password, $hash)){
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

        return $app->redirect('../?message=Invalid%20username%20and%20password-if%20you%20tried%20more%20than%203%20times%20your%20account%20will%20be%20locked%20for%205%20hours');
    }
    
});

$app->get('/reset', function(Request $request) use($app) {
    return $app['twig']->render('reset.twig', array(
    ));
});

$app->post('/reset/send', function(Request $request) use($app) {
    //TODO update db for this post
    $username = $request->get('username');
    $secAnswer = $request->get('securityAnswer');
    $password = password_hash($request->get('password'), PASSWORD_DEFAULT);
    
    
    //start
    $app['monolog']->addDebug('SELECT sec_answer, bad_attempts, (age(last_login_tm)> INTERVAL \'5 hours\')as age FROM user_table WHERE user_nm=' . $username .';');
    
    //Select user record from user_table
    $st = $app['pdo']->prepare('SELECT sec_answer, bad_attempts, (age(last_login_tm)> INTERVAL \'5 hours\')as age FROM user_table WHERE user_nm=?;');
    $st->bindValue(1, $username, PDO::PARAM_STR);
    $st->execute();
    
    //extract hashed password from table
    $hash='';
    $bad_attempts=0;
    $last_login_tm='';
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $hash = $row['sec_answer'];
        $bad_attempts=$row['bad_attempts'];
        $last_login_tm=$row['age'];
    }
    
    $app['monolog']->addDebug('$hash=' . $hash);
    $app['monolog']->addDebug('$$bad_attempts=' . $bad_attempts);
    $app['monolog']->addDebug('$last_login_tm=' . $last_login_tm);
    $app['monolog']->addDebug('password_verify($password, $hash)' . password_verify($secAnswer, $hash));
    
    //PreValidate: Need to get a row in the database
    //     * (bad_attempts <= 3 OR logged in more than 5 hours ago)
    //Validate: Password hash matches hash in the database
    if($hash !== '' && ($bad_attempts <= 3 || $last_login_tm === true) && password_verify($secAnswer, $hash)){
        $app['monolog']->addDebug('USER IS VERIFIED');
        $st = $app['pdo']->prepare('UPDATE user_table SET bad_attempts = 0, password=?, last_login_tm=CURRENT_TIMESTAMP WHERE user_nm=?;');
        $st->bindValue(1, $password, PDO::PARAM_STR);
        $st->bindValue(2, $username, PDO::PARAM_STR);
        $st->execute();
        
        $app['monolog']->addDebug('Reset bad attempts to 0 and user password');
        //set the session ID
        $app['session']->set('user', $username);
        $app['monolog']->addDebug('Set session user to ' . $username);
        
        return $app->redirect('/?message=Password%20reset');
    }else{
        //Invalid User - set bad attempts > 3 for higher threshold of security
        $app['monolog']->addDebug('USER IS DENIED');
        $st = $app['pdo']->prepare('UPDATE user_table SET bad_attempts = bad_attempts +3, last_login_tm=CURRENT_TIMESTAMP WHERE user_nm=?;');
        $st->bindValue(1, $username, PDO::PARAM_STR);
        $st->execute();
        $app['monolog']->addDebug('Incremented bad attempts');
        
        return $app->redirect('../?message=Invalid%20username%20and%20security%20answer-If%20you%20try%20more%20than%202%20times%20your%20account%20will%20be%20locked%20for%205%20hours');
    }
    return $app->redirect('/');
    
});
    
$app->get('/inbox/', function() use($app) {
    
    $username = $app['session']->get('user');
    $app['monolog']->addDebug('Inbox user is ' . $username);
    
    if($username == ''){
        return $app->redirect('../');//go back to login
    }else{
        
        //TODO get public key for to
        $app['monolog']->addDebug('SELECT public_key FROM user_table where user_nm=' . $username .';');
        $st = $app['pdo']->prepare("SELECT public_key FROM user_table where user_nm=?;");
        $st->bindValue(1, $username, PDO::PARAM_STR);
        $st->execute();
        
        $secret_key = '';
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $app['monolog']->addDebug('Public Key ' . $row['public_key']);
            $secret_key = $row['public_key'];

        }
        
        $app['monolog']->addDebug('SELECT * FROM message_table where to_id=' . $username . ';');
        $st = $app['pdo']->prepare('SELECT * FROM message_table where to_id=?;');
        $st->bindValue(1, $username, PDO::PARAM_STR);
        $st->execute();
        
        $data = array();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            
            $secret_iv = $secret_key . 'iv';
            $encrypt_method = "AES-256-CBC";
            $key = hash( 'sha256', $secret_key );
            $iv = substr( hash( 'sha256', $secret_iv ), 0, 16 );

            $app['monolog']->addDebug('row[\'text\']' . $row['text']);
            $app['monolog']->addDebug('data[\'text\']' . $data['text']);
            
            $app['monolog']->addDebug('openssl_decrypt( base64_decode(' . $row['text'] .' ),' . $encrypt_method . ',' . $key . ', 0,'. $iv . ');');
            
            $output = openssl_decrypt( base64_decode( $row['text'] ), $encrypt_method, $key, 0, $iv );
            $app['monolog']->addDebug('DECRYPT' . $output);
            
            $row['text'] = $output;
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
