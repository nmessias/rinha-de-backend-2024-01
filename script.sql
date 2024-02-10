CREATE UNLOGGED TABLE clientes (
    id INTEGER PRIMARY KEY NOT NULL,
    saldo INTEGER NOT NULL,
    limite INTEGER NOT NULL,
    CHECK (saldo >= limite * -1)
);

CREATE UNLOGGED TABLE transacoes (
    id SERIAL PRIMARY KEY,
    valor INTEGER NOT NULL,
    descricao VARCHAR(10) NOT NULL,
    tipo CHAR(1) NOT NULL,
    realizada_em TIMESTAMP NOT NULL,
    id_cliente INTEGER NOT NULL
);

CREATE INDEX ix_transacoes_id_cliente ON transacoes (
    id_cliente ASC
);

CREATE FUNCTION criar_transacao(id_cliente INTEGER, valor INTEGER, descricao VARCHAR(10), tipo CHAR(1))
RETURNS TABLE (cliente_saldo INTEGER, cliente_limite INTEGER) AS $$
BEGIN
  -- Check if cliente exists
  IF NOT EXISTS (SELECT 1 FROM clientes WHERE id = id_cliente) THEN
    RAISE EXCEPTION 'Cliente com id % não encontrado.', id_cliente;
  END IF;

  -- Insert transacao
  INSERT INTO transacoes (valor, descricao, tipo, realizada_em, id_cliente)
  VALUES (valor, descricao, tipo, NOW(), id_cliente);

  -- Update clientes.saldo and return values
  RETURN QUERY
  UPDATE clientes SET saldo = saldo + valor WHERE id = id_cliente
  RETURNING saldo AS cliente_saldo, limite AS cliente_limite;
END;
$$ LANGUAGE plpgsql;

DO $$
BEGIN
  INSERT INTO clientes (id, saldo, limite)
  VALUES
    (1, 0, 1000 * 100),
    (2, 0, 800 * 100),
    (3, 0, 10000 * 100),
    (4, 0, 100000 * 100),
    (5, 0, 5000 * 100);
END; $$