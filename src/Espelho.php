<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Espelho EM ARQUIVO da maior versão vista por chave (B-02b), em GLPI_PLUGIN_DOC_DIR/instalador/.
 * Quem escreve só no banco (SQLi em outro plugin) não alcança este arquivo: apagar ou rebaixar a linha de
 * versoes_vistas não esconde a versão mais nova. Só sobe; nunca grava versão menor.
 */
final class Espelho
{
    public function __construct(private readonly ?string $arquivo)
    {
    }

    /** @return array<string,string> */
    private function ler(): array
    {
        if ($this->arquivo === null || !is_file($this->arquivo)) {
            return [];
        }
        $j = json_decode((string) file_get_contents($this->arquivo), true);
        $out = [];
        foreach (is_array($j) ? $j : [] as $k => $v) {
            if (is_string($k) && preg_match(Manifesto::RE_CHAVE, $k) === 1 && is_string($v) && preg_match(Manifesto::RE_VERSAO, $v) === 1) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public function maximo(string $chave): ?string
    {
        return $this->ler()[$chave] ?? null;
    }

    public function registrar(string $chave, string $versao): void
    {
        if ($this->arquivo === null || preg_match(Manifesto::RE_CHAVE, $chave) !== 1 || preg_match(Manifesto::RE_VERSAO, $versao) !== 1) {
            return;
        }
        $dir = dirname($this->arquivo);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return;
        }
        $lock = @fopen($this->arquivo . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            return;
        }
        try {
            $atual = $this->ler();
            if (isset($atual[$chave]) && Manifesto::compararVersao($versao, $atual[$chave]) <= 0) {
                return;
            }
            $atual[$chave] = $versao;
            ksort($atual);
            $tmp = $this->arquivo . '.tmp' . getmypid();
            $old = umask(0177);
            try {
                $ok = @file_put_contents($tmp, json_encode($atual, JSON_UNESCAPED_SLASHES));
            } finally {
                umask($old);
            }
            if ($ok !== false) {
                @rename($tmp, $this->arquivo);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
