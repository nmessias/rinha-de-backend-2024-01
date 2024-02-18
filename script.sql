CREATE UNLOGGED TABLE clientes (
    id INTEGER PRIMARY KEY NOT NULL,
    saldo INTEGER NOT NULL,
    limite INTEGER NOT NULL
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

CREATE TYPE criar_transacao_result AS (
  resultado integer,
  saldo integer,
  limite integer
);

CREATE OR REPLACE FUNCTION criar_transacao(id_cliente INTEGER, valor INTEGER, descricao VARCHAR(10), tipo CHAR(1))
RETURNS criar_transacao_result AS $$
DECLARE 
  cliente_data RECORD;
  result criar_transacao_result;
  update_client_result RECORD;
  copy_valor INTEGER;
BEGIN
	SELECT * INTO cliente_data FROM clientes WHERE id = id_cliente;
	
	IF cliente_data IS NULL THEN
		SELECT -1, -1, -1 INTO result;
    RETURN result;
	END IF;

  IF tipo = 'd' THEN
    copy_valor := valor * -1;
  ELSE
    copy_valor := valor;
  END IF;

  UPDATE clientes SET saldo = saldo + copy_valor WHERE id = id_cliente AND (copy_valor > 0 OR saldo + copy_valor >= limite * -1)
    RETURNING saldo, limite INTO update_client_result;
    
  IF update_client_result.saldo IS NULL THEN 
		SELECT -2, -2, -2 INTO result;
  ELSE
    INSERT INTO transacoes (valor, descricao, tipo, realizada_em, id_cliente)
    VALUES (valor, descricao, tipo, NOW(), id_cliente);

    SELECT 0 AS resultado, update_client_result.saldo, update_client_result.limite INTO result;
  END IF;

  RETURN result;  
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