<?php

declare(strict_types=1);

namespace xJustJqy\Rankup;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\Player;
use xenialdan\apibossbar\BossBar;
use pocketmine\item\Item;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\inventory\BaseInventory;
use pocketmine\utils\TextFormat as TF;
use pocketmine\scheduler\ClosureTask;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\level\Position;

class Main extends PluginBase implements Listener {

    const ALERT = TF::BOLD.TF::GOLD."(".TF::RESET.TF::GOLD."!".TF::BOLD.")".TF::RESET.TF::GOLD." ";
    const ERROR = TF::BOLD.TF::RED."(".TF::RESET.TF::RED."!".TF::BOLD.")".TF::RESET.TF::RED." ";
    const INFO = TF::BOLD.TF::DARK_AQUA."(".TF::RESET.TF::DARK_AQUA."!".TF::BOLD.")".TF::RESET.TF::DARK_AQUA." ";
    const SUCCESS = TF::BOLD.TF::GREEN."(".TF::RESET.TF::GREEN."!".TF::BOLD.")".TF::RESET.TF::GREEN." ";

    private $players;
    private $config;
    private $logger;
    private $cmdReturn = false;
    private $bars;
    private $economy;
    private $mineranks = [];
    private $mines = [];

    public function onEnable() {
        $this->logger = $this->getServer()->getLogger();
        @mkdir($this->getDataFolder());
        $this->saveResource("players.yml");
        $this->saveResource("config.yml");
        $this->saveResource("mines.yml");
        $this->saveResource("updater.json");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML, []);
        $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);
        $this->mines = new Config($this->getDataFolder() . "mines.yml", Config::YAML, []);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $plugin = $this;
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($plugin) : void {
            $plugin->initEconomy();
        }), 50);
    }

    private function disable() {
        $this->getServer()->getPluginManager()->disablePlugin($this);
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args) : bool {
        $logger = $this->getServer()->getLogger();
        if(!$sender instanceof Player) {
            $this->cmdReturn = true;
        }
        if($cmd->getName() === "rankup") {
            if(!$sender instanceof Player) {
                $sender->sendMessage(self::ERROR."You must use this command in-game!");
                return true;
            }
            $this->handleRankup($sender);
            $this->cmdReturn = true;
        }
        if($cmd->getName() === "rankupmax") {
            if(!$sender instanceof Player) {
                $sender->sendMessage(self::ERROR."You must use this command in-game!");
                return true;
            }
            $this->handleMaxRankup($sender);
            $this->cmdReturn = true;
        }
        if($cmd->getName() === "setlevel") {
            if(isset($args[0]) && isset($args[1]) && $sender->hasPermission("admin.command")) {
                $target = $this->getServer()->getPlayer($args[0]);
                if($target instanceof Player) {
                    if(in_array(strtolower(strval($args[1])), array_keys($this->mineranks))) {
                        $this->players->setNested($target->getXuid() . ".minerank", strtoupper(strval($args[1])));
                        $this->serverConfigSave();
                        $this->DoBossbar($target);
                        $this->cmdReturn = true;
                    }else{
                        $sender->sendMessage(self::ERROR."That is not a valid level!");
                    }
                }else{
                    $sender->sendMessage(self::ERROR."Player is not online!");
                }
            }
        }
        if($cmd->getName() === "createminewarp") {
            if(isset($args[0])) {
                $name = strtolower(strval($args[0]));
                $pos = $sender->getPosition();
                $pos->x = $pos->getFloorX() + 0.5;
                $pos->z = $pos->getFloorZ() + 0.5;
                $this->createWarp($name, $pos, $sender->getLevel()->getFolderName());
                $sender->sendMessage(self::SUCCESS . "Succesfully created warp ".$args[0]);
                $this->cmdReturn = true;
            }else{
                $sender->sendMessage(self::ERROR."Must specify a name!");
            }
        }

        if($cmd->getName() === "deleteminewarp") {
            if(isset($args[0])) {
                $name = strtolower(strval($args[0]));
                if(!is_null($this->mines->get($name))) {
                    $this->mines->remove($name);
                    $sender->sendMessage(self::SUCCESS . "Succesfully deleted warp ".$args[0]);
                    $this->cmdReturn = true;
                }
            }
        }

        if($cmd->getName() === "mines") {
            $list = "";
            if($sender->hasPermission("rankup.command.admin")) {
                try {
                    $version = (json_decode(file_get_contents($this->getDataFolder() . "updater.json")))->version;
                    $repoVersion = (json_decode(file_get_contents("https://github.com/xJustJqy/Rankup-MineWarps/raw/main/updater.json")))->version;
                    if($version !== $repoVersion) {
                        $list .= self::ERROR . "This plugin is not up to date! Please download the lates version at https://github.com/xJustJqy/Rankup-MineWarps/releases/tag/".$repoVersion."\n";
                    }else{
                        $list .= self::SUCCESS . "This plugin is up to date!\n";
                    }
                } catch($err) {}
            }
            $list .= self::INFO . "Mine Warps:\n";
            foreach(array_keys($this->mines->getAll()) as $mine) {
                $list .= "- " . $mine . "\n";
            }
            $sender->sendMessage($list);
            $this->cmdReturn = true;
        }

        if($cmd->getName() === "mine") {
            if(isset($args[0])) {
                try{
                    if(!is_null($this->mines->get( strtolower( strval( $args[0] ) ) ) ) ) {
                        $canGo = false;
                        if($this->config->get("rankup-style") === "numerical") {
                            if(floatval($args[0]) < floatval($this->players->getNested($sender->getXuid() . ".minerank"))) {
                                $canGo = true;   
                            }
                        }else{
                            if(array_search(strtolower( strval( $args[0] ) ), array_keys($this->mineranks)) < array_search(strtolower( strval( $this->players->getNested($sender->getXuid() . ".minerank") ) ), array_keys($this->mineranks))){
                                $canGo = true;   
                            }
                        }
                        if($canGo === false) {
                            $sender->sendMessage(self::ERROR . "You cannot go to this mine!");
                            return true;
                        }
                        $pos = $this->mines->get( strtolower( strval( $args[0] ) ) );
                        if(is_bool($pos)) {
                            $sender->sendMessage(self::ERROR."That mine does not exist!");
                            return true;
                        }
                        $x = $pos[0];
                        $y = $pos[1];
                        $z = $pos[2];
                        $levelName = $pos[3];
                        $level;
                        $worldsAPI = $this->getServer()->getPluginManager()->getPlugin("MultiWorld");
                        if(is_null($worldsAPI) || $worldsAPI->isDisabled()) {
                            $level = $this->getServer()->getLevelByName($levelName);
                        }else{
                            $level = $worldsAPI->getLevel($levelName);
                        }
                        if(is_null($level)) {
                            $sender->sendMessage(self::ERROR."There was an error retreiving the warp's level. Maybe it isn't loaded?");
                        }else{
                            $sender->teleport(new Position($x, $y, $z, $level));
                        }
                        $this->cmdReturn = true;
                    }
                } catch ($e) {
                    $sender->sendMessage(self::ERROR."That mine does not exist!");
                    $this->cmdReturn = true;
                }
            }
        }
            if($sender instanceof Player) {
                $this->DoBossbar($sender);
            }
            return $this->cmdReturn;
    }

    public function PlayerJoined(PlayerJoinEvent $event) {
        $player = $event->getPlayer();
        $xuid = $player->getXuid();
        if(is_null($this->players->get($xuid))) {
            $this->players->set($xuid, []);
            $this->players->setNested($xuid.".minerank", strtoupper(strval($this->minerank[0])));
            $this->players->save();
            $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);
        }
        if($this->config->get("rankup-style") === "numerical" && (in_array(strtolower(strval($this->players->getNested($xuid . ".minerank"))),["a","b","c","d","e","f","g","h","i","j","k","l","m","n","o","p","q","r","s","t","u","v","w","x","y","z"]))) {
            $this->players->set($xuid, []);
            $this->players->setNested($xuid.".minerank", "1");
            $this->players->save();
            $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);
        }
        if($this->config->get("rankup-style") === "alphabetic" && !is_nan(floatval($this->players->getNested($xuid . ".minerank")))) {
            $this->players->set($xuid, []);
            $this->players->setNested($xuid.".minerank", "A");
            $this->players->save();
            $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);
        }
        $this->DoBossbar($player);
    }

    public function serverConfigSave() {
        $this->players->save();
        $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);
    }

    public function DoBossbar(Player $player, bool $ru = false) {
        if(!$player instanceof Player) return;
        $this->serverConfigSave();
        
        $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);
        if(!isset($this->bars[strtolower($player->getName())])) {
            $this->bars[strtolower($player->getName())] = new BossBar();
        }
        $this->players = new Config($this->getDataFolder() . "players.yml", Config::YAML, []);

        $index = array_search(strtolower(strval($this->players->getNested($player->getXuid() . ".minerank"))), array_keys($this->mineranks));
        if(isset(array_values($this->mineranks)[$index + 1])){
            $next = array_values($this->mineranks)[$index + 1];
            $nextmine = strtoupper(strval(array_keys($this->mineranks)[$index + 1]));
            $rankUp = " -> [".$nextmine."] ".$this->convertToShort($next);
        }else{
            $next = 1;
            $rankUp = "";
        }
        $title = str_replace(["{level}", "{nextlevel}", "{money}", "{nextlevelcost}", "&"], [$this->players->getNested($player->getXuid() . ".minerank"), isset($nextmine) ? $nextmine : "MAX", $this->convertToShort($this->economy->myMoney($player)), isset($nextmine) ? $this->convertToShort($next) : "", TF::ESCAPE], $this->config->get("format"));
        $this->bars[strtolower($player->getName())]->setTitle($title)->setPercentage(($this->economy->myMoney($player) / $next > 0) ? $this->economy->myMoney($player) / $next : 1 / 100)->addPlayer($player);
    }

    public function handleRankup(Player $player) {
        $name = $player->getXuid();
        $balance = (int) $this->economy->myMoney($player);
        $current = strtolower(strval($this->players->getNested($player->getXuid() . ".minerank")));
        $index = array_search($current, array_keys($this->mineranks));
        if ($index !== false && is_numeric($index)) {}else{
            return;
        }
        if (!isset(array_values($this->mineranks)[intval($index + 1)])) {
            $player->sendMessage(self::ERROR."You are at the max minerank!");
            return;
        }
        $next = array_values($this->mineranks)[$index + 1];
        $nextmine = strval(array_keys($this->mineranks)[$index + 1]);
        if($balance > $next) {
            $this->players->setNested($player->getXuid() . ".minerank", strtoupper($nextmine));
            $this->economy->reduceMoney($player, $next);
            $this->serverConfigSave();
            $this->DoBossbar($player, true);
            $player->sendMessage(self::SUCCESS."Succesfully ranked up to ".strtoupper($nextmine));
        }else{
            $player->sendMessage(self::ERROR."You do not have enough money!");
        }
    }

    public function log($message = "N/A") {
        return $this->getServer()->getLogger()->info(strval($message));
    }

    public function convertToShort($num) {
        $num = strval($num);
        if(strlen($num) == 12) {
            $digits = substr($num,0,3);
            $ex = substr($num,3,4);
            $num = $digits.".".$ex."B";
        }elseif(strlen($num) == 11) {
            $digits = substr($num,0,2);
            $ex = substr($num,2,3);
            $num = $digits.".".$ex."B";
        }elseif(strlen($num) == 10) {
            $digits = substr($num,0,1);
            $ex = substr($num,1,2);
            $num = $digits.".".$ex."B";
        }elseif(strlen($num) == 9) {
            $digits = substr($num,0,3);
            $ex = substr($num,3,4);
            $num = $digits.".".$ex."M";
        }elseif(strlen($num) == 8) {
            $digits = substr($num,0,2);
            $ex = substr($num,2,3);
            $num = $digits.".".$ex."M";
        }elseif(strlen($num) == 7) {
            $digits = substr($num,0,1);
            $ex = substr($num,1,2);
            $num = $digits.".".$ex."M";
        }
        return $num;
    }

    public function handleMaxRankup(Player $player) {
        $can = true;
        while($can === true) {
            $name = $player->getXuid();
            $balance = (int) $this->economy->myMoney($player);
            $current = strtolower(strval($this->players->getNested($player->getXuid() . ".minerank")));
            $index = strval(array_search($current, array_keys($this->mineranks)));
            if ($index !== false && is_numeric($index)) {
                if (!isset(array_keys($this->mineranks)[intval($index + 1)])) {
                    $can = false;
                }else{
                    $next = array_values($this->mineranks)[$index + 1];
                    $nextmine = strval(strval(array_keys($this->mineranks)[$index + 1]));
                    if($balance > $next) {
                        $this->players->setNested($player->getXuid() . ".minerank", strtoupper($nextmine));
                        $this->economy->reduceMoney($player, $next);
                        $this->serverConfigSave();
                        $this->DoBossbar($player);
                        $player->sendMessage(self::SUCCESS."Succesfully ranked up to ".strtoupper($nextmine));
                    }else{
                        $can = false;
                    }
                }
            }
        }
    }
    public function initializeMines() {
        $max = (int) $this->config->get("max-rank");
        $style = (string) $this->config->get("rankup-style");
        $multiplier = (int) $this->config->get("rankup-multiplier");
        $coststyle = (string) $this->config->get("cost-multiplier-style");
        $basecost = (int) $this->config->get("first-rankup-cost");
        $previous = $basecost;
        if($style === "numerical") {
            for($i = 1; $i <= $max; $i++) {
                if($coststyle === "linear") {
                    $this->mineranks[strval($i)] = $previous + ($basecost * $multiplier);
                }elseif($coststyle === "exponential") {
                    $this->mineranks[strval($i)] = $previous * $multiplier;
                }else{
                    $this->log(self::ERROR."Config is incorrectly setup!");
                    $this->disable();
                    return;
                }
                $previous = $this->mineranks[strval($i)];
            }
        }elseif($style === "alphabetic") {
            $alphabet = explode(" ","a b c d e f g h i j k l m n o p q r s t u v w x y z");
            foreach($alphabet as $letter) {
                if($coststyle === "linear") {
                    $this->mineranks[$letter] = $previous + ($basecost * $multiplier);
                }elseif($coststyle === "exponential") {
                    $this->mineranks[$letter] = $previous * $multiplier;
                }else{
                    $this->log(self::ERROR."Config is incorrectly setup!");
                    $this->disable();
                    return;
                }
                $previous = $this->mineranks[$letter];
            }
        }else{
            $this->log(self::ERROR."Config is incorrectly setup!");
            $this->disable();
        }
    }

    private function createWarp(string $name, $position, $levelName) {
        $this->mines->set($name, [$position->x, $position->y, $position->z, $levelName]);
        $this->mines->save();
    }

    public function initEconomy() {
        $this->economy = $this->getServer()->getPluginManager()->getPlugin($this->config->get("economy"));
            if(is_null($this->economy) || $this->economy->isDisabled()) {
                $this->logger->info(self::ERROR . "No economy plugin was found!");
                $this->disable();
            }else{
                $this->initializeMines();
                if($this->config->get("economy") === "EconomyAPI") {
                    new MoneyChangeListener($this);
                }
            }
    }
}
