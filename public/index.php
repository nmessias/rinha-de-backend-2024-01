<?php

// Prevent worker script termination when a client connection is interrupted
ignore_user_abort(true);

$repository = new Repository();

// Handler outside the loop for better performance (doing less work)
$handler = static function () use ($repository) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');

    $pathParts = explode('/', $_SERVER["REQUEST_URI"]);
    $idCliente = (int)$pathParts[2];

    echo match ($pathParts[3]) {
        'transacoes' => createTransacao($idCliente, $repository),
        'extrato' => getExtrato($idCliente, $repository),
        default => http_response_code(404) ? '{}' : '{}',
    };
};

function getExtrato(int $idCliente, Repository $repository): string {
    $results = $repository->getExtrato($idCliente);
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

function createTransacao(int $idCliente, Repository $repository): string {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!isValidTransacao($payload)) {
        return '{}';
    }

    $tipo = $payload['tipo'];
    $valor = $payload['valor'];
    $descricao = $payload['descricao'];

    if ($tipo === 'd') {
        $valor *= -1;
    }

    $result = $repository->criarTransacao($idCliente, $valor, $descricao, $tipo);

    $saldo = $result['cliente_saldo'];
    $limit = $result['cliente_limite'];

    if ($saldo === -1) {
        http_response_code(404);

        return "{\"mensagem\": \"Cliente com id $idCliente não encontrado.\"}";
    }
        
    if ($saldo === -2) {
        http_response_code(422);

        return '{"mensagem": "Limite não disponível."}';
    }

    return json_encode(['saldo' => $result['cliente_saldo'], 'limite' => $result['cliente_limite']]);
}

class Repository {
    private Pdo $pdo;
    private PDOStatement $extratoStmt;
    private PDOStatement $transacaoStmt;

    function __construct() {
        $this->pdo = new PDO('pgsql:host=db;port=5432;dbname=rinha', 'admin', '123', [PDO::ATTR_PERSISTENT => true]);
        
        $this->extratoStmt = $this->pdo->prepare(
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
            WHERE c.id = ?
            ORDER BY t.id DESC
            LIMIT 10;"
        );

        $this->transacaoStmt = $this->pdo->prepare('SELECT * FROM criar_transacao(?, ?, ?, ?)');
    }

    public function criarTransacao(int $idCliente, int $valor, string $descricao, string $tipo): array
    {
        $this->transacaoStmt->execute([$idCliente, $valor, $descricao, $tipo]);
        $result = $this->transacaoStmt->fetch(PDO::FETCH_ASSOC);
        $this->transacaoStmt->closeCursor();

        return $result;
    }

    public function getExtrato(int $idCliente): array
    {
        $this->extratoStmt->execute([$idCliente]);

        return $this->extratoStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function close(): void
    {
        $this->transacaoStmt = null;
        $this->extratoStmt = null;
        $this->pdo = null;
    }
}

function isValidTransacao(array $payload): bool
{
    if (!isset($payload['valor']) || !isset($payload['tipo']) || !isset($payload['descricao'])) {
        http_response_code(422);

        return false;
    }

    $lengthDescricao = strlen($payload['descricao']);
    if (!is_int($payload['valor']) || $lengthDescricao < 1 || $lengthDescricao > 10 || !in_array($payload['tipo'], ['c', 'd'])) {
        http_response_code(422);

        return false;
    }

    return true;
}

for($nbRequests = 0, $running = true; $nbRequests < 2000 && $running; ++$nbRequests) {
    $running = \frankenphp_handle_request($handler);

    // Call the garbage collector to reduce the chances of it being triggered in the middle of a page generation
    gc_collect_cycles();
}

$repository->close();