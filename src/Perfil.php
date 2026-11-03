<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * Aba "Instalador" em Administração > Perfis. A gravação é do próprio Profile::update(), que persiste
 * qualquer direito presente em glpi_profilerights. Dois direitos: plugin_instalador (ver / preparar) e
 * plugin_instalador_aprovar (fatia c). Nenhum é concedido no install.
 */
class Perfil extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Instalador';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry(self::getTypeName());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }
        (new self())->showForm((int) $item->getID());

        return true;
    }

    public function showForm(int $profiles_id): bool
    {
        if (!Session::haveRight('profile', READ)) {
            return false;
        }
        $profile = new Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return false;
        }
        $canedit = Session::haveRight('profile', UPDATE);

        echo "<form method='post' action='" . Profile::getFormURL() . "'>";
        $profile->displayRightsChoiceMatrix([
            ['itemtype' => self::class, 'label' => 'Instalador: ver pacotes e planos (Ver) e preparar / enviar pacote offline / concluir (Atualizar)', 'field' => 'plugin_instalador'],
            ['itemtype' => PerfilAprovar::class, 'label' => 'Instalador: APROVAR, desfazer e destravar chave (exige MFA e senha na hora; fatia c)', 'field' => 'plugin_instalador_aprovar'],
        ], [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);
        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
        }
        Html::closeForm();

        return true;
    }

    /** Bits oferecidos na linha deste itemtype. */
    public function getRights($interface = 'central'): array
    {
        return [READ => __('Read'), UPDATE => __('Update')];
    }
}
