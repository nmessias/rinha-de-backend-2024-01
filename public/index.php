<?php

$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=rinha', 'admin', '123', [PDO::ATTR_PERSISTENT => true, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$extratoStmt = $pdo->prepare('SELECT get_extrato(?);');
$transacaoStmt = $pdo->prepare('SELECT * FROM criar_transacao(?, ?, ?, ?);');
const TIPOS = ['c', 'd'];

$handler = static function () use ($extratoStmt, $transacaoStmt) {
    header('Content-Type: application/json');
    $pathParts = explode('/', $_SERVER["REQUEST_URI"]);
    $idCliente = (int)$pathParts[2];

    [$responseCode, $responseBody] = match ($pathParts[3]) {
        'transacoes' => transacao($idCliente, $transacaoStmt),
        'extrato' => extrato($idCliente, $extratoStmt),
        default => [404, null],
    };

    http_response_code($responseCode);
    echo $responseBody;
};

function extrato(int $idCliente, PDOStatement $stmt): array {
    $stmt->execute([$idCliente]);
    $result = $stmt->fetch()['get_extrato'];
    if ($result === null) {
        return [404, null];
    }
    
    return [200, $result];
}

function transacao(int $idCliente, PDOStatement $stmt): array {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!isset($payload['valor']) || !isset($payload['tipo']) || !isset($payload['descricao'])) {
        return [422, null];
    }

    ['tipo' => $tipo, 'valor' => $valor, 'descricao' => $descricao] = $payload;
    $lengthDescricao = strlen($descricao);
    if (!is_int($valor) || $lengthDescricao < 1 || $lengthDescricao > 10 || !in_array($tipo, TIPOS)) {
        return [422, null];
    }

    $stmt->execute([$idCliente, $valor, $descricao, $tipo]);
    ['code' => $code, 'saldo' => $saldo, 'limite' => $limite] = $stmt->fetch();
    
    if ($code === -1) {
        return [404, null];
    }    
    if ($code === -2) {
        return [422, null];
    };

    return [200, "{\"saldo\": $saldo, \"limite\": $limite}"];
}

while(true) {
    \frankenphp_handle_request($handler);
}