<?php

namespace xJustJqy\Rankup;

use onebone\economyapi\event\money\MoneyChangedEvent;
use pocketmine\event\Listener;

class MoneyChangeListener implements Listener {

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
    }

    public function onMoneyChanged(MoneyChangedEvent $event) {
        $this->plugin->DoBossbar($this->plugin->getServer()->getPlayer($event->getUsername()));
    }

}