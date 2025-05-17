<?php
/**
 * Script de login automático para ZKBio CVAccess
 * Este script faz login automaticamente no sistema e atualiza o ID de sessão no proxy.php
 */

// Configurações
$zkbio_url = 'http://192.168.1.148:8098';
$username = 'admin'; // Substitua pelo seu nome de usuário
$password = 'Zerozero9217!'; // Substitua pela sua senha
$proxy_file = __DIR__ . '/proxy.php';
$log_file = __DIR__ . '/auto_login.log';

// Função para fazer log
function log_message($message) {
    global $log_file;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($log_file, "$timestamp $message\n", FILE_APPEND);
}

// Iniciar o processo
log_message("=== INICIANDO PROCESSO DE LOGIN AUTOMÁTICO ===");

// Inicializar cURL
$ch = curl_init();

// Criar o hash MD5 da senha
$password_md5 = md5($password);

// Configurar a primeira requisição para carregar a página de login e obter cookies iniciais
curl_setopt_array($ch, [
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

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

log_message("Carregando página inicial: Status HTTP $http_code");

// Extrair o token do navegador se presente na página
$browser_token = '';
if (preg_match('/browser-token:\s*([a-f0-9]+)/', $response, $matches)) {
    $browser_token = $matches[1];
    log_message("Token do navegador extraído: $browser_token");
} else {
    log_message("Não foi possível encontrar o token do navegador na página");
}

// Configurar a requisição de login
curl_setopt_array($ch, [
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
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

log_message("Login: Status HTTP $http_code");

// Verificar se o login foi bem-sucedido (normalmente há redirecionamento)
if ($http_code >= 300 && $http_code < 400) {
    log_message("Login bem-sucedido com redirecionamento");
} elseif ($http_code == 200) {
    // Em alguns casos, o login bem-sucedido retorna 200 OK com uma resposta JSON
    log_message("Login com status 200. Verificando resposta...");
    if (strpos($response, '"ret":"ok"') !== false || strpos($response, '"success":true') !== false) {
        log_message("Login bem-sucedido com resposta JSON");
    } else {
        log_message("Login falhou. Resposta: " . substr($response, 0, 500));
        curl_close($ch);
        exit(1);
    }
} else {
    log_message("Login falhou. Código HTTP: $http_code");
    curl_close($ch);
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
    // Tentar extrair de outro local (do arquivo de cookies)
    if (file_exists('cookies.txt')) {
        $cookie_content = file_get_contents('cookies.txt');
        if (preg_match('/192\.168\.1\.148.*?SESSION\s+([^\s]+)/', $cookie_content, $matches)) {
            $session_id = $matches[1];
            log_message("ID de sessão extraído do arquivo de cookies: $session_id");
        }
    }
    
    if (empty($session_id)) {
        log_message("Não foi possível extrair o ID de sessão!");
        curl_close($ch);
        exit(1);
    }
}

// Fechar cURL
curl_close($ch);

// Atualizar o arquivo proxy.php com o novo ID de sessão
if (file_exists($proxy_file)) {
    $proxy_content = file_get_contents($proxy_file);
    
    // Substituir o ID de sessão antigo pelo novo
    $updated_content = preg_replace(
        '/\$session\s*=\s*\'[^\']*\';/', 
        "\$session = '$session_id';", 
        $proxy_content
    );
    
    if ($updated_content !== $proxy_content) {
        file_put_contents($proxy_file, $updated_content);
        log_message("Arquivo proxy.php atualizado com o novo ID de sessão");
    } else {
        log_message("Não foi possível atualizar o arquivo proxy.php");
    }
} else {
    log_message("Arquivo proxy.php não encontrado em: $proxy_file");
}

// Registrar o término bem-sucedido
log_message("=== PROCESSO DE LOGIN AUTOMÁTICO CONCLUÍDO COM SUCESSO ===");
echo "Login automático concluído. Verifique o arquivo de log para mais detalhes.\n";
echo $session_id;
?>
