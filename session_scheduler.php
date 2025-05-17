<?php
/**
 * Script para agendar o login automático periodicamente
 * Este script deve ser adicionado ao cron para manter a sessão sempre ativa
 */

// Configuração
$login_script_path = __DIR__ . '/auto_login.php';
$log_file = __DIR__ . '/scheduler.log';

// Função para fazer log
function log_message($message) {
    global $log_file;
    $timestamp = date('[Y-m-d H:i:s]');
    file_put_contents($log_file, "$timestamp $message\n", FILE_APPEND);
}

// Registrar início
log_message("=== INICIANDO VERIFICAÇÃO DE SESSÃO ===");

// Verificar se o script de login existe
if (!file_exists($login_script_path)) {
    log_message("ERRO: Script de login não encontrado em: $login_script_path");
    exit(1);
}

// Verificar se precisamos fazer login novamente
$need_login = false;

// 1. Verificar se o ID de sessão atual funciona tentando fazer uma requisição de teste
$proxy_url = 'http://localhost/proxy.php';  // Ajuste conforme necessário
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $proxy_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'type=test',
    CURLOPT_HEADER => false
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Se a resposta indicar um erro de sessão ou a requisição falhar
if ($http_code != 200 || strpos($response, 'session') !== false && strpos($response, 'expired') !== false) {
    log_message("Sessão inválida ou expirada (HTTP Code: $http_code)");
    $need_login = true;
} else {
    log_message("Sessão atual parece válida");
}

// 2. Verificar o tempo desde o último login (opcional)
$session_time_file = __DIR__ . '/last_login_time.txt';
if (file_exists($session_time_file)) {
    $last_login_time = (int)file_get_contents($session_time_file);
    $current_time = time();
    $hours_since_login = ($current_time - $last_login_time) / 3600;
    
    // Se passaram mais de 1 hora (ajuste conforme necessário)
    if ($hours_since_login > 1) {
        log_message("Última atualização de sessão foi há $hours_since_login horas (> 1 hora)");
        $need_login = true;
    } else {
        log_message("Sessão foi atualizada há $hours_since_login horas (< 1 hora)");
    }
} else {
    log_message("Não foi encontrado registro da última atualização de sessão");
    $need_login = true;
}

// Se precisar fazer login
if ($need_login) {
    log_message("Executando script de login automático...");
    
    // Executar o script de login
    $output = [];
    $return_var = 0;
    exec("php $login_script_path 2>&1", $output, $return_var);
    
    // Verificar o resultado
    if ($return_var === 0) {
        log_message("Login realizado com sucesso");
        // Atualizar o timestamp do último login
        file_put_contents($session_time_file, time());
    } else {
        log_message("ERRO ao executar o login: " . implode("\n", $output));
    }
} else {
    log_message("Não é necessário fazer login novamente no momento");
}

log_message("=== VERIFICAÇÃO DE SESSÃO CONCLUÍDA ===");
?>
