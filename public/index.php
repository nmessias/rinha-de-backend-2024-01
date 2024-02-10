<?php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

$pdo = new \PDO('pgsql:host=db;port=5432;dbname=rinha', 'admin', '123',);

// Handler outside the loop for better performance (doing less work)
$handler = static function () use ($pdo) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'];
    $pathParts = explode('/', $path);

    if (!isValidRequest($pathParts, $method)) {
        http_response_code(404);

        return;
    }

    $idCliente = (int)$pathParts[2];
    echo match ($pathParts[3]) {
        'transacoes' => createTransacao($idCliente, $pdo),
        'extrato' => getExtrato($idCliente, $pdo),
        default => http_response_code(404) ? '{}' : '{}',
    };
};

function getExtrato(int $idCliente, Pdo $pdo): string {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);

        return '{}';
    }

    $stmt = $pdo->prepare(
        "SELECT 
            c.saldo AS total,
	        TO_CHAR(NOW(), 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS data_extrato,
	        c.limite,
            t.valor,
            t.tipo,
            t.descricao,
            TO_CHAR(t.realizada_em, 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS realizada_em
        FROM clientes c
        LEFT JOIN transacoes t ON t.id_cliente = c.id	
        WHERE c.id = :idCliente
        ORDER BY t.id DESC
        LIMIT 10;"
    );

    $stmt->execute([':idCliente' => $idCliente]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($results) === 0) {
        http_response_code(404);

        return "{\"mensagem\": \"Cliente com id $idCliente não encontrado.\"}";
    }

    $saldo = [
        'total' => $results[0]['total'],
        'data_extrato' => $results[0]['data_extrato'],
        'limite' => $results[0]['limite'],
    ];

    $transacoes = [];
    if ($results[0]['valor'] !== null) {
        foreach ($results as $transacao) {
            $transacoes[] = [
                'valor' => $transacao['valor'] < 0 ? $transacao['valor'] * -1 : $transacao['valor'],
                'tipo' => $transacao['tipo'],
                'descricao' => $transacao['descricao'],
                'realizada_em' => $transacao['realizada_em'],
            ];
        }
    }

    return json_encode([
        'saldo' => $saldo,
        'ultimas_transacoes' => $transacoes,
    ]);
}

function createTransacao(int $idCliente, Pdo $pdo): string {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!isValidTransacao($payload)) {
        return '{}';
    }

    $tipo = $payload['tipo'];
    $valor = (int)$payload['valor'];
    $descricao = $payload['descricao'];

    if ($tipo === 'd') {
        $valor *= -1;
    }

    $stmt = $pdo->prepare('SELECT * FROM criar_transacao(:idCliente, :valor, :descricao, :tipo)');
    try {
        if ($stmt->execute([':idCliente' => $idCliente, ':valor' => $valor, ':descricao' => $descricao, ':tipo' => $tipo])) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt->closeCursor();

            return json_encode(['saldo' => $result['cliente_saldo'], 'limite' => $result['cliente_limite']]);
        }
    } catch (PDOException $e) {
        if ($e->errorInfo[0] === 'P0001') {
            http_response_code(404);

            return "{\"mensagem\": \"Cliente com id $idCliente não encontrado.\"}";
        } else {
            http_response_code(422);

            return '{"mensagem": "Limite não disponível."}';
        }
    }
    
    return '{}';
}

function isValidTransacao(array $payload): bool
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);

        return false;
    }

    if (!isset($payload['valor']) || !isset($payload['tipo']) || !isset($payload['descricao'])) {
        http_response_code(422);

        return false;
    }

    $lengthDescricao = strlen($payload['descricao']);
    if ($lengthDescricao < 1 || $lengthDescricao > 10 || !in_array($payload['tipo'], ['c', 'd'])) {
        http_response_code(422);

        return false;
    }

    return true;
}

function isValidRequest(array $pathParts, string $method): bool {
    if (count($pathParts) !== 4 || $pathParts[1] !== 'clientes' || !ctype_digit($pathParts[2])) {

        return false;
    }

    if ($method !== 'POST' && $method !== 'GET') {

        return false;
    }

    if ($method === 'POST' && $pathParts[3] !== 'transacoes') {

        return false;
    }

    if ($method === 'GET' && $pathParts[3] !== 'extrato') {

        return false;
    }

    return true;
}

for($nbRequests = 0, $running = true; ($nbRequests < 200) && $running; ++$nbRequests) {
    $running = \frankenphp_handle_request($handler);

    // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
    gc_collect_cycles();
}