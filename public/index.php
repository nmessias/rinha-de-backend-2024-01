<?php

$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=rinha', 'admin', '123', [PDO::ATTR_PERSISTENT => true]);
$saldoStmt = $pdo->prepare('SELECT saldo AS total, NOW() AS data_extrato, limite FROM transacoes where id_cliente = ? ORDER BY id DESC LIMIT 1;');
$transacoesStmt = $pdo->prepare('SELECT valor, tipo, descricao, realizada_em FROM transacoes WHERE id_cliente = ? ORDER BY id DESC LIMIT 10;');
$criarTransacaoStmt = $pdo->prepare('SELECT * FROM criar_transacao(?, ?, ?, ?);');

$handler = static function () use ($saldoStmt, $transacoesStmt, $criarTransacaoStmt) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    $pathParts = explode('/', $_SERVER["REQUEST_URI"]);
    $idCliente = (int)$pathParts[2];

    echo match ($pathParts[3]) {
        'transacoes' => createTransacao($idCliente, $criarTransacaoStmt),
        'extrato' => getExtrato($idCliente, $saldoStmt, $transacoesStmt),
        default => http_response_code(404) ? '' : '',
    };
};

function getExtrato(int $idCliente, PDOStatement $saldoStmt, PDOStatement $transacoesStmt): string {
    $saldoStmt->execute([$idCliente]);
    $saldo = $saldoStmt->fetch(PDO::FETCH_ASSOC);
    if (!$saldo) {
        http_response_code(404);

        return "";
    }

    $transacoesStmt->execute([$idCliente]);
    $transacoes = $transacoesStmt->fetchAll(PDO::FETCH_ASSOC);

    return json_encode([
        'saldo' => $saldo,
        'ultimas_transacoes' => $transacoes,
    ]);
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
    if (!is_int($valor) || $lengthDescricao < 1 || $lengthDescricao > 10 || !in_array($tipo, ['c', 'd'])) {
        http_response_code(422);
        
        return '';
    }

    $criarTransacaoStmt->execute([$idCliente, $valor, $descricao, $tipo]);
    $response = $criarTransacaoStmt->fetch(PDO::FETCH_ASSOC);

    $resultado = $response['resultado'];
    if ($resultado === -1) {
        http_response_code(404);

        return '';
    }
        
    if ($resultado === -2) {
        http_response_code(422);

        return '';
    }

    $saldo = $response['saldo'];
    $limite = $response['limite'];

    return "{\"saldo\": $saldo, \"limite\": $limite}";
}

while(true) {
    \frankenphp_handle_request($handler);

    // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
    gc_collect_cycles();
}
