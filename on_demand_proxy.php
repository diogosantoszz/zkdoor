<?php
/**
 * Proxy integrado que faz login e abre a porta sob demanda
 * Combina o auto_login.php e proxy.php em um único arquivo
 */

// Configurações
$zkbio_url = 'http://192.168.1.148:8098';
$username = 'admin';
$password = 'Zerozero9217!'; // A senha fica aqui apenas por compatibilidade com o código original
$log_file = __DIR__ . '/on_demand_proxy.log';

// Habilitar logs
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Habilitar CORS para desenvolvimento
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json');

// Função para fazer log
function log_message($message) {
    global $log_file;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($log_file, "$timestamp $message\n", FILE_APPEND);
}

// Se for uma requisição OPTIONS (preflight), apenas responder com cabeçalhos
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Verificar se a requisição é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_message("Método inválido: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'msg' => 'Método inválido']);
    exit;
}

// Registrar início da requisição
log_message("=== NOVA REQUISIÇÃO INICIADA ===");

// ETAPA 1: Fazer login e obter session ID
// -----------------------------------------
log_message("ETAPA 1: Fazendo login para obter session ID");

// Inicializar cURL para login
$login_ch = curl_init();

// Criar o hash MD5 da senha
$password_md5 = md5($password);

// Configurar a primeira requisição para carregar a página de login e obter cookies iniciais
curl_setopt_array($login_ch, [
    CURLOPT_URL => "$zkbio_url/",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => 'cookies.txt',
    CURLOPT_COOKIEFILE => 'cookies.txt',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

$response = curl_exec($login_ch);
$http_code = curl_getinfo($login_ch, CURLINFO_HTTP_CODE);

log_message("Carregando página inicial: Status HTTP $http_code");

// Extrair o token do navegador
$browser_token = '';
if (preg_match('/browser-token:\s*([a-f0-9]+)/', $response, $matches)) {
    $browser_token = $matches[1];
    log_message("Token do navegador extraído: $browser_token");
} else {
    log_message("Não foi possível encontrar o token do navegador na página");
}

// Configurar a requisição de login
curl_setopt_array($login_ch, [
    CURLOPT_URL => "$zkbio_url/login.do",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'username' => $username,
        'password' => $password_md5
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        $browser_token ? "browser-token: $browser_token" : '',
        'Referer: ' . $zkbio_url . '/'
    ],
    CURLOPT_HEADER => true,
    CURLOPT_COOKIEJAR => 'cookies.txt',
    CURLOPT_COOKIEFILE => 'cookies.txt',
]);

// Executar o login
$response = curl_exec($login_ch);
$http_code = curl_getinfo($login_ch, CURLINFO_HTTP_CODE);

log_message("Login: Status HTTP $http_code");

// Verificar se o login foi bem-sucedido
if ($http_code >= 300 && $http_code < 400) {
    log_message("Login bem-sucedido com redirecionamento");
} elseif ($http_code == 200) {
    if (strpos($response, '"ret":"ok"') !== false || strpos($response, '"success":true') !== false) {
        log_message("Login bem-sucedido com resposta JSON");
    } else {
        log_message("Login falhou. Resposta: " . substr($response, 0, 500));
        echo json_encode(['success' => false, 'msg' => 'Falha no login']);
        curl_close($login_ch);
        exit(1);
    }
} else {
    log_message("Login falhou. Código HTTP: $http_code");
    echo json_encode(['success' => false, 'msg' => 'Falha no login', 'http_code' => $http_code]);
    curl_close($login_ch);
    exit(1);
}

// Extrair o cookie de sessão dos headers
$session_id = '';
preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $cookies_matches);
foreach ($cookies_matches[1] as $cookie) {
    if (strpos($cookie, 'SESSION=') === 0) {
        $session_id = substr($cookie, strlen('SESSION='));
        log_message("ID de sessão extraído: $session_id");
        break;
    }
}

// Verificar se foi obtido o ID de sessão
if (empty($session_id)) {
    // Tentar extrair do arquivo de cookies
    if (file_exists('cookies.txt')) {
        $cookie_content = file_get_contents('cookies.txt');
        if (preg_match('/192\.168\.1\.148.*?SESSION\s+([^\s]+)/', $cookie_content, $matches)) {
            $session_id = $matches[1];
            log_message("ID de sessão extraído do arquivo de cookies: $session_id");
        }
    }
    
    if (empty($session_id)) {
        log_message("Não foi possível extrair o ID de sessão!");
        echo json_encode(['success' => false, 'msg' => 'Não foi possível obter sessão']);
        curl_close($login_ch);
        exit(1);
    }
}

// Fechar cURL de login
curl_close($login_ch);

log_message("Login concluído com sucesso. Session ID: $session_id");

// ETAPA 2: Obter o formulário de controle da porta
// ------------------------------------------------
log_message("ETAPA 2: Obtendo o formulário de controle da porta");

// Parâmetros da primeira requisição
$step1_fields = 'getDoorIds=&type=openDoor&ids=402881149584c3270195851b8fbf3966';

// Inicializar cURL para a primeira requisição
$ch1 = curl_init();

// Configurar cURL para a primeira requisição
curl_setopt_array($ch1, [
    CURLOPT_URL => 'http://192.168.1.148:8098/accDoor.do',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $step1_fields,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'pragma: no-cache',
        'cache-control: no-cache',
        'browser-token: ' . $browser_token,
        'X-Requested-With: XMLHttpRequest',
        'Origin: http://192.168.1.148:8098',
        'Referer: http://192.168.1.148:8098/main.do?home&selectSysCode=Acc'
    ],
    CURLOPT_COOKIE => "SESSION=$session_id; menuType=icon-only",
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_VERBOSE => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

// Executar a requisição do passo 2
log_message("Enviando requisição de etapa 2");
$response1 = curl_exec($ch1);
$err1 = curl_error($ch1);
$http_code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);

// Verificar se a requisição foi bem-sucedida
if ($err1 || $http_code1 != 200) {
    log_message("Erro na etapa 2: $err1, HTTP Code: $http_code1");
    echo json_encode([
        'success' => false, 
        'msg' => 'Erro na etapa 2', 
        'error' => $err1,
        'http_code' => $http_code1
    ]);
    curl_close($ch1);
    exit;
}

// Extrair o pass token (se necessário)
$pass_token = '';
if (preg_match('/passToken\s*=\s*[\'"]([^\'"]+)[\'"]/', $response1, $matches)) {
    $pass_token = $matches[1];
    log_message("Pass token extraído: $pass_token");
} else {
    // Usar o valor fixo como no script original
    $pass_token = '277ebc1a517a490da4d8df8997538f23';
    log_message("Usando pass token fixo: $pass_token");
}

// Fechar a primeira conexão cURL
curl_close($ch1);

// ETAPA 3: Enviar o comando de abertura da porta
// ----------------------------------------------
log_message("ETAPA 3: Enviando o comando de abertura da porta");

// Parâmetros para abrir a porta
$step2_fields = 'type=openDoor&ids=402881149584c3270195851b8fbf3966&name=192.168.1.240-1&disabledDoorsName=&offlineDoorsName=&notSupportDoorsName=&userLoginPwd=Zerozero9217!&loginPwd=49b5359155eb3816e6985a46ea1d24b5&openInterval=30&browserToken=' . $browser_token . '&passToken=' . $pass_token;

// Inicializar cURL para a terceira requisição
$ch2 = curl_init();

// Configurar cURL para a terceira requisição
curl_setopt_array($ch2, [
    CURLOPT_URL => 'http://192.168.1.148:8098/accDoor.do?openDoor',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $step2_fields,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'pragma: no-cache',
        'cache-control: no-cache',
        'browser-token: ' . $browser_token,
        'X-Requested-With: XMLHttpRequest',
        'Origin: http://192.168.1.148:8098',
        'Referer: http://192.168.1.148:8098/main.do?home&selectSysCode=Acc'
    ],
    CURLOPT_COOKIE => "SESSION=$session_id; menuType=icon-only",
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_VERBOSE => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

// Executar a requisição do passo 3
log_message("Enviando requisição de etapa 3 (comando de abertura)");
$response2 = curl_exec($ch2);
$err2 = curl_error($ch2);
$http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

// Registrar resposta do passo 3
log_message("Resposta HTTP Code da etapa 3: $http_code2");
log_message("Resposta da etapa 3: $response2");

// Verificar se a requisição foi bem-sucedida
if ($err2) {
    echo json_encode([
        'success' => false, 
        'msg' => 'Erro na etapa 3', 
        'error' => $err2,
        'http_code' => $http_code2
    ]);
} else {
    // Tentar interpretar a resposta
    $json_response = json_decode($response2, true);
    
    if ($json_response && isset($json_response['success'])) {
        // Se for JSON válido
        echo $response2;
    } else if (strpos($response2, '"status":201') !== false || strpos($response2, '"error":"Created"') !== false) {
        // Se for a resposta com status 201
        echo json_encode([
            'success' => true,
            'ret' => 'ok',
            'msg' => 'Porta aberta com sucesso (201 Created)',
            'raw_response' => $response2
        ]);
    } else {
        // Para qualquer outra resposta
        echo json_encode([
            'success' => true, // Assumindo sucesso se não houver erro
            'msg' => 'Comando enviado com sucesso',
            'raw_response' => $response2,
            'http_code' => $http_code2
        ]);
    }
}

// Fechar a terceira conexão cURL
curl_close($ch2);
log_message("Processo de três etapas finalizado");
?>
