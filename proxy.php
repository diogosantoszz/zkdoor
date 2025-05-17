<?php
/**
 * Versão que replica o fluxo exato de requisições do HAR
 * usando um processo de duas etapas
 */

// Habilitar logs detalhados
error_reporting(E_ALL);
ini_set('display_errors', 1);
$log_file = 'two_step_door_proxy.log';

function log_message($message) {
    global $log_file;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($log_file, "$timestamp $message\n", FILE_APPEND);
}

// Registrar início da requisição
log_message("=== NOVA REQUISIÇÃO INICIADA ===");

// Habilitar CORS para desenvolvimento
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Content-Type: application/json');

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

// Usar EXATAMENTE a mesma sessão do HAR bem-sucedido
$session = 'YjVlYzg5MjYtMDQ5Yy00MmRjLThlNDMtYTY4NmRmMWM2ZDZm';

// PASSO 1: Obter o formulário de controle da porta
// ---------------------------------------------
log_message("PASSO 1: Obtendo o formulário de controle da porta");

// Parâmetros exatos da primeira requisição como visto no HAR
$step1_fields = 'getDoorIds=&type=openDoor&ids=402881149584c3270195851b8fbf3966';

// Inicializar cURL para a primeira requisição
$ch1 = curl_init();

// Configurar cURL EXATAMENTE como no HAR para a primeira requisição
curl_setopt_array($ch1, [
    CURLOPT_URL => 'http://192.168.1.148:8098/accDoor.do',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $step1_fields,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'pragma: no-cache',
        'cache-control: no-cache',
        'browser-token: df88a7cacdf5513e1bcf44fbb90bf391',
        'X-Requested-With: XMLHttpRequest',
        'Origin: http://192.168.1.148:8098',
        'Referer: http://192.168.1.148:8098/main.do?home&selectSysCode=Acc'
    ],
    CURLOPT_COOKIE => "SESSION=$session; menuType=icon-only",
    CURLOPT_HEADER => true, // Capturar headers para analisar cookies
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_VERBOSE => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

// Capturar detalhes verbosos
$verbose1 = fopen('php://temp', 'w+');
curl_setopt($ch1, CURLOPT_STDERR, $verbose1);

// Executar a requisição do passo 1
log_message("Enviando requisição de passo 1");
$response1 = curl_exec($ch1);
$err1 = curl_error($ch1);
$http_code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);

// Obter informações detalhadas
rewind($verbose1);
$verbose_log1 = stream_get_contents($verbose1);
log_message("Log detalhado do passo 1: " . $verbose_log1);

// Verificar se a primeira requisição foi bem-sucedida
if ($err1 || $http_code1 != 200) {
    log_message("Erro no passo 1: $err1, HTTP Code: $http_code1");
    echo json_encode([
        'success' => false, 
        'msg' => 'Erro na primeira etapa', 
        'error' => $err1,
        'http_code' => $http_code1
    ]);
    curl_close($ch1);
    exit;
}

// Extrair cookies da resposta (caso tenham sido atualizados)
preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response1, $cookies_matches);
$updated_cookies = [];
if (!empty($cookies_matches[1])) {
    foreach ($cookies_matches[1] as $cookie) {
        $updated_cookies[] = $cookie;
    }
    log_message("Cookies atualizados: " . implode("; ", $updated_cookies));
}

// Separar headers e corpo da resposta
list($header, $body) = explode("\r\n\r\n", $response1, 2);
log_message("Resposta do passo 1 recebida");

// Fechar a primeira conexão cURL
curl_close($ch1);

// Log de progresso
log_message("Passo 1 concluído com sucesso. Iniciando passo 2.");

// PASSO 2: Enviar o comando de abertura da porta
// ---------------------------------------------

// Parâmetros exatos para abrir a porta, conforme capturado no HAR bem-sucedido
$step2_fields = 'type=openDoor&ids=402881149584c3270195851b8fbf3966&name=192.168.1.240-1&disabledDoorsName=&offlineDoorsName=&notSupportDoorsName=&userLoginPwd=Zerozero9217!&loginPwd=49b5359155eb3816e6985a46ea1d24b5&openInterval=30&browserToken=df88a7cacdf5513e1bcf44fbb90bf391&passToken=277ebc1a517a490da4d8df8997538f23';

// Inicializar cURL para a segunda requisição
$ch2 = curl_init();

// Configurar cURL para a segunda requisição
curl_setopt_array($ch2, [
    CURLOPT_URL => 'http://192.168.1.148:8098/accDoor.do?openDoor',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $step2_fields,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'pragma: no-cache',
        'cache-control: no-cache',
        'browser-token: df88a7cacdf5513e1bcf44fbb90bf391',
        'X-Requested-With: XMLHttpRequest',
        'Origin: http://192.168.1.148:8098',
        'Referer: http://192.168.1.148:8098/main.do?home&selectSysCode=Acc'
    ],
    CURLOPT_COOKIE => "SESSION=$session; menuType=icon-only", // Usando os mesmos cookies
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_VERBOSE => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);

// Capturar detalhes verbosos do passo 2
$verbose2 = fopen('php://temp', 'w+');
curl_setopt($ch2, CURLOPT_STDERR, $verbose2);

// Executar a requisição do passo 2
log_message("Enviando requisição de passo 2 (comando de abertura)");
$response2 = curl_exec($ch2);
$err2 = curl_error($ch2);
$http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);

// Obter informações detalhadas do passo 2
rewind($verbose2);
$verbose_log2 = stream_get_contents($verbose2);
log_message("Log detalhado do passo 2: " . $verbose_log2);

// Registrar resposta do passo 2
log_message("Resposta HTTP Code do passo 2: $http_code2");
log_message("Resposta do passo 2: $response2");

// Verificar se a segunda requisição foi bem-sucedida
if ($err2) {
    echo json_encode([
        'success' => false, 
        'msg' => 'Erro na segunda etapa', 
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

// Fechar a segunda conexão cURL
curl_close($ch2);
log_message("Processo de duas etapas finalizado");
?>
