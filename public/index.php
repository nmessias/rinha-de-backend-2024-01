<?php

$pdo = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=rinha', 'admin', '123', [PDO::ATTR_PERSISTENT => true]);

$handler = static function () use ($pdo) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    $pathParts = explode('/', $_SERVER["REQUEST_URI"]);
    $idCliente = (int)$pathParts[2];

    echo match ($pathParts[3]) {
        'transacoes' => createTransacao($idCliente, $pdo),
        'extrato' => getExtrato($idCliente, $pdo),
        default => http_response_code(404) ? '' : '',
    };
};

function getExtrato(int $idCliente, Pdo $pdo): string {
    $result = $pdo
        ->query("SELECT saldo as total, now() as data_extrato, limite, valor, tipo, descricao, realizada_em FROM transacoes WHERE id_cliente = $idCliente ORDER BY id DESC LIMIT 10;")
        ->fetchAll(PDO::FETCH_ASSOC);

    if (count($result) === 0) {
        http_response_code(404);

        return '';
    }

    $saldo = [
        'total' => $result[0]['total'],
        'data_extrato' => $result[0]['data_extrato'],
        'limite' => $result[0]['limite'],
    ];

    $transacoes = [];
    foreach ($result as $transacao) {
        $transacao[] = [
            'valor' => $transacao['valor'],
            'tipo' => $transacao['tipo'],
            'descricao' => $transacao['descricao'],
            'realizada_em' => $transacao['realizada_em'],
        ];
    }

    return json_encode([
        'saldo' => $saldo,
        'ultimas_transacoes' => $transacoes,
    ]);
}

function createTransacao(int $idCliente, Pdo $pdo): string {
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

    $response = $pdo->query("SELECT * FROM criar_transacao($idCliente, $valor, '$descricao', '$tipo')")->fetch(PDO::FETCH_ASSOC);

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
