<?php

$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=rinha', 'admin', '123', [PDO::ATTR_PERSISTENT => true]);
$extratoStmt = $pdo->prepare('SELECT get_extrato(?);');
$criarTransacaoStmt = $pdo->prepare('SELECT * FROM criar_transacao(?, ?, ?, ?);');
const TIPOS = ['c', 'd'];

$handler = static function () use ($extratoStmt, $criarTransacaoStmt) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    $pathParts = explode('/', $_SERVER["REQUEST_URI"]);
    $idCliente = (int)$pathParts[2];

    echo match ($pathParts[3]) {
        'transacoes' => createTransacao($idCliente, $criarTransacaoStmt),
        'extrato' => getExtrato($idCliente, $extratoStmt),
        default => http_response_code(404) ? '' : '',
    };
};

function getExtrato(int $idCliente, PDOStatement $extratoStmt): string {
    $extratoStmt->execute([$idCliente]);
    $extrato = $extratoStmt->fetch(PDO::FETCH_ASSOC)['get_extrato'];
    if ($extrato === null) {
        http_response_code(404);
        return "";
    }

    return $extrato;
}

function createTransacao(int $idCliente, PDOStatement $criarTransacaoStmt): string {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!isset($payload['valor']) || !isset($payload['tipo']) || !isset($payload['descricao'])) {
        http_response_code(422);
        return '';
    }

    $tipo = $payload['tipo'];
    $valor = $payload['valor'];
    $descricao = $payload['descricao'];
    $lengthDescricao = strlen($descricao);
    if (!is_int($valor) || $lengthDescricao < 1 || $lengthDescricao > 10 || !in_array($tipo, TIPOS)) {
        http_response_code(422);
        return '';
    }

    $criarTransacaoStmt->execute([$idCliente, $valor, $descricao, $tipo]);
    $response = $criarTransacaoStmt->fetch(PDO::FETCH_ASSOC);
    $code = $response['code'];
    if ($code === -1) {
        http_response_code(404);
        return '';
    }
        
    if ($code === -2) {
        http_response_code(422);
        return '';
    }

    $saldo = $response['saldo'];
    $limite = $response['limite'];

    return "{\"saldo\": $saldo, \"limite\": $limite}";
}

while(true) {
    \frankenphp_handle_request($handler);
}
