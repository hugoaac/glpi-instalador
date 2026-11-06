<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use Throwable;

/** Cola entre o GLPI (páginas e cron) e a lógica pura. Nada aqui decide regra de segurança. */
final class Servico
{
    public const CATALOGO_A_CADA = 3600;

    public static function armazem(): ArmazemDb
    {
        return new ArmazemDb();
    }

    public static function painel(): PainelApi
    {
        return new PainelInformante();
    }

    public static function preparador(): Preparador
    {
        $ctx = Contexto::doGlpi();

        return new Preparador($ctx, self::armazem(), new Assinatura(), self::painel(), self::espelho($ctx));
    }

    /** B-02b: espelho em arquivo da maior versão vista, em GLPI_PLUGIN_DOC_DIR/instalador/. */
    public static function espelho(?Contexto $ctx = null): Espelho
    {
        $ctx ??= Contexto::doGlpi();

        return new Espelho($ctx->docDir . '/versoes_vistas.json');
    }

    /** @return list<array{ok:?bool,nome:string,detalhe:string}> ok=null: aviso informativo */
    public static function requisitos(): array
    {
        $r = [];
        $r[] = ['ok' => extension_loaded('sodium'), 'nome' => 'Extensão sodium', 'detalhe' => 'verificação Ed25519 do pacote'];
        $r[] = ['ok' => extension_loaded('zip') && extension_loaded('zlib'), 'nome' => 'Extensões zip e zlib', 'detalhe' => 'leitura do pacote'];
        $falta = PainelInformante::requisito();
        $r[] = ['ok' => $falta === null, 'nome' => 'Plugin informante >= ' . PainelInformante::VERSAO_MINIMA . ' ativo', 'detalhe' => $falta ?? 'ok (transporte e identidade do ambiente)'];
        $n = count(Publicadores::LISTA);
        $r[] = ['ok' => Publicadores::prontoParaCliente(), 'nome' => 'Chaves de publicador fixadas', 'detalhe' => $n . ' de ' . Publicadores::CHAVES_PARA_CLIENTE
            . ($n < Publicadores::CHAVES_PARA_CLIENTE ? ': falta a RESERVA. Nenhum GLPI de cliente recebe este instalador sem as duas chaves.' : '')];
        $mp = defined('GLPI_MARKETPLACE_DIR') ? (string) constant('GLPI_MARKETPLACE_DIR') : '';
        $r[] = ['ok' => $mp !== '' && is_dir($mp) && is_writable($mp), 'nome' => 'GLPI_MARKETPLACE_DIR gravável', 'detalhe' => $mp];
        $dirs = Contexto::visaoAtual();
        $fpm  = Config::visaoFpm();
        $r[] = ['ok' => $fpm === null ? null : ($fpm['plugins'] === $dirs['plugins'] && $fpm['marketplace'] === $dirs['marketplace']),
            'nome' => 'Diretórios de plugins iguais no FPM e na CLI',
            'detalhe' => $fpm === null ? 'o FPM ainda não gravou a sua visão (abra esta página pelo navegador)' : 'GLPI_PLUGINS_DIRECTORIES: ' . implode(' , ', $dirs['plugins'])];
        $desligado = defined('GLPI_MARKETPLACE_ALLOW_OVERRIDE') && Contexto::overrideDesligado(constant('GLPI_MARKETPLACE_ALLOW_OVERRIDE'));
        $r[] = ['ok' => $desligado, 'nome' => 'GLPI_MARKETPLACE_ALLOW_OVERRIDE desligado (C1)', 'detalhe' => 'valor lido neste processo: ' . $dirs['allow_override']
            . ($desligado ? '' : ' (o GLPI o trata como ligado; use 0 no ambiente, não "false")')];

        return $r;
    }

    /** @return array{ok:bool,erro:?string,novos:int,conhecidos:int,rejeitados:list<string>,retirados:int,vistas:int} */
    public static function atualizarCatalogo(): array
    {
        $r = Catalogo::atualizar(self::painel(), self::armazem(), new Assinatura(), self::espelho());
        Config::set(['catalogo_em' => (string) time(), 'catalogo_status' => json_encode($r, JSON_UNESCAPED_UNICODE)]);

        return $r;
    }

    /** Cria a operação PEDIDA. Uma por chave aberta de cada vez. @return array{ok:bool,msg:string,id:?int} */
    public static function pedir(int $pacoteId, int $usuario): array
    {
        $arm = self::armazem();
        $p   = $arm->pacotePorId($pacoteId);
        if ($p === null) {
            return ['ok' => false, 'msg' => 'pacote inexistente', 'id' => null];
        }
        if (preg_match(Manifesto::RE_CHAVE, (string) $p['chave']) !== 1) {
            return ['ok' => false, 'msg' => 'chave inválida', 'id' => null];
        }
        foreach ($arm->operacoesPorEstado(['PEDIDA', 'PREPARANDO', 'PLANO_PRONTO']) as $o) {
            if ($o['chave'] === $p['chave']) {
                return ['ok' => false, 'msg' => 'já existe a operação #' . $o['id'] . ' aberta para ' . $p['chave'], 'id' => null];
            }
        }
        try {
            // B-07: a unicidade "uma operação aberta por chave" é do banco (coluna gerada + UNIQUE), não deste foreach.
            $id = $arm->criarOperacao([
                'tipo' => 'INSTALAR', 'chave' => $p['chave'], 'versao_para' => $p['versao'], 'pacotes_id' => (int) $p['id'],
                'pedido_por' => $usuario, 'inicio' => date('Y-m-d H:i:s'),
            ]);
        } catch (Recusa $e) {
            return ['ok' => false, 'msg' => 'já existe operação aberta para ' . Texto::esc((string) $p['chave']), 'id' => null];
        }
        $arm->journal($id, 'PEDIR', 'DEPOIS', ['pacotes_id' => (int) $p['id'], 'chave' => $p['chave'], 'versao' => $p['versao']]);

        return ['ok' => true, 'msg' => 'operação #' . $id . ' pedida; o cron prepara em até 1 minuto (php front/cron.php --force InstaladorExecutor)', 'id' => $id];
    }
}
