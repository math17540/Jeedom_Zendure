<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('zendure');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$passwords = array();
foreach ($eqLogics as $eqLogic) {
    if ($eqLogic->getConnection() == 'mqtt') {
        $passwords[$eqLogic->getId()] = zendure::devicePassword($eqLogic->getLogicalId());
    }
}
$models = array();
foreach (zendure::MODELS as $id => $def) {
    $models[$id] = array('name' => $def['name'], 'connection' => $def['connection']);
}
$groups = array('mqtt' => '{{MQTT local (redirection Bluetooth)}}', 'http' => '{{API locale zenSDK}}', 'zmeter' => '{{Compteurs}}', 'shelly' => '{{Compteurs}}');
sendVarToJS('zendurePasswords', $passwords);
sendVarToJS('zendureModels', $models);
?>

<div class="row row-overflow">
    <div class="col-xs-12 eqLogicThumbnailDisplay">
        <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
        <div class="eqLogicThumbnailContainer">
            <div class="cursor logoPrimary" id="bt_zendureAdd">
                <i class="fas fa-plus-circle"></i><br><span>{{Ajouter}}</span>
            </div>
            <div class="cursor logoSecondary" id="bt_zendureDiscover">
                <i class="fas fa-search"></i><br><span>{{Rechercher (mDNS)}}</span>
            </div>
            <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
                <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
            </div>
        </div>
        <legend><i class="fas fa-car-battery"></i> {{Mes équipements Zendure}}</legend>
        <?php
        if (count($eqLogics) == 0) {
            echo '<br><div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucun équipement. Les appareils MQTT apparaissent seuls dès leur premier rapport ; ajoutez un SolarFlow ou un compteur avec « Ajouter » ou « Rechercher ».}}</div>';
        } else {
            echo '<div class="input-group" style="margin:5px;">';
            echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
            echo '<div class="input-group-btn"><a id="bt_resetSearch" class="btn roundedRight" style="width:30px"><i class="fas fa-times"></i></a></div>';
            echo '</div>';
            echo '<div class="eqLogicThumbnailContainer">';
            foreach ($eqLogics as $eqLogic) {
                $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
                echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
                echo '<img src="' . $plugin->getPathImgIcon() . '"/><br>';
                echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
                echo '<span class="hiddenAsCard displayTableRight"><span class="label label-info">' . zendure::MODELS[$eqLogic->getModel()]['name'] . '</span></span>';
                echo '</div>';
            }
            echo '</div>';
        }
        ?>
    </div>

    <div class="col-xs-12 eqLogic" style="display: none;">
        <div class="input-group pull-right" style="display:inline-flex;">
            <span class="input-group-btn">
                <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
                </a><a class="btn btn-sm btn-info" id="bt_zendureRefresh"><i class="fas fa-sync"></i><span class="hidden-xs"> {{Rafraîchir}}</span>
                </a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
                </a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
                </a>
            </span>
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
            <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Équipement}}</a></li>
            <li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
        </ul>
        <div class="tab-content">
            <div role="tabpanel" class="tab-pane active" id="eqlogictab">
                <form class="form-horizontal">
                    <fieldset>
                        <div class="col-lg-6">
                            <legend><i class="fas fa-wrench"></i> {{Paramètres généraux}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Nom de l'équipement}}</label>
                                <div class="col-sm-6">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                                    <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement}}">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                                <div class="col-sm-6">
                                    <select id="sel_object" class="eqLogicAttr form-control" data-l1key="object_id">
                                        <option value="">{{Aucun}}</option>
                                        <?php
                                        foreach ((jeeObject::buildTree(null, false)) as $object) {
                                            echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Catégorie}}</label>
                                <div class="col-sm-6">
                                    <?php
                                    foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                                        echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" >' . $value['name'] . '</label>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Options}}</label>
                                <div class="col-sm-6">
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                                    <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Description}}</label>
                                <div class="col-sm-6"><textarea class="form-control eqLogicAttr autogrow" data-l1key="comment"></textarea></div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <legend><i class="fas fa-car-battery"></i> {{Appareil}}</legend>
                            <div class="form-group">
                                <label class="col-sm-4 control-label">{{Modèle}}</label>
                                <div class="col-sm-6">
                                    <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="model" id="sel_zendureModel">
                                        <?php
                                        $current = null;
                                        foreach (zendure::MODELS as $id => $def) {
                                            $g = $groups[$def['connection']];
                                            if ($g !== $current) {
                                                echo ($current !== null ? '</optgroup>' : '') . '<optgroup label="' . $g . '">';
                                                $current = $g;
                                            }
                                            echo '<option value="' . $id . '">' . $def['name'] . '</option>';
                                        }
                                        echo '</optgroup>';
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="zendureConn" data-connection="mqtt">
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{deviceId (identifiant MQTT)}}
                                        <sup><i class="fas fa-question-circle tooltips" title="{{Rempli automatiquement au premier rapport. Saisissez-le pour un ajout manuel.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-6"><input type="text" class="eqLogicAttr form-control" data-l1key="logicalId" placeholder="Ab12Cd34"></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Mot de passe MQTT}}</label>
                                    <div class="col-sm-6"><span class="label label-default" id="span_zendurePassword"></span></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{productKey}}</label>
                                    <div class="col-sm-6"><span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="productKey"></span></div>
                                </div>
                            </div>

                            <div class="zendureConn" data-connection="http zmeter shelly" style="display:none;">
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Adresse IP}}</label>
                                    <div class="col-sm-6"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.1.50"></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Port}}</label>
                                    <div class="col-sm-2"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="80"></div>
                                </div>
                            </div>

                            <div class="zendureConn" data-connection="zmeter shelly" style="display:none;">
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Lecture toutes les (secondes)}}
                                        <sup><i class="fas fa-question-circle tooltips" title="{{Avec le démon démarré. Sans démon : chaque minute.}}"></i></sup></label>
                                    <div class="col-sm-2"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="interval" placeholder="5"></div>
                                </div>
                                <div class="form-group zendureConn" data-connection="shelly">
                                    <label class="col-sm-4 control-label">{{Shelly détecté}}</label>
                                    <div class="col-sm-6">
                                        <span class="eqLogicAttr label label-default" data-l1key="configuration" data-l2key="shellyModel"></span>
                                        <span class="eqLogicAttr label label-info" data-l1key="configuration" data-l2key="shellyProfile"></span>
                                    </div>
                                </div>
                                <div class="alert alert-info">{{Puissance réseau positive = soutirage, négative = injection (pinces côté réseau). Le Shelly ne doit pas avoir de mot de passe.}}</div>
                            </div>

                            <div class="zendureConn" data-connection="http" style="display:none;">
                                <div class="alert alert-info">{{L'API locale doit être activée : dans l'application Zendure, ajoutez un HEMS puis quittez l'application.}}</div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Numéro de série}}
                                        <sup><i class="fas fa-question-circle tooltips" title="{{Obligatoire pour envoyer des commandes. Rempli automatiquement à la première lecture.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-6"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="sn"></div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{Commandes par}}
                                        <sup><i class="fas fa-question-circle tooltips" title="{{Automatique : MQTT si l'appareil publie sur le broker (MQTT configuré dans le zenSDK, port 1883 sans TLS), sinon HTTP.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-6">
                                        <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cmdMode">
                                            <option value="auto">{{Automatique (MQTT si disponible)}}</option>
                                            <option value="mqtt">{{MQTT}}</option>
                                            <option value="http">{{HTTP}}</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="col-sm-4 control-label">{{smartMode}}
                                        <sup><i class="fas fa-question-circle tooltips" title="{{Les changements de limite et de mode AC ne sont pas écrits en mémoire flash (recommandé par Zendure pour les réglages fréquents). Au redémarrage de l'appareil, les valeurs enregistrées reviennent.}}"></i></sup>
                                    </label>
                                    <div class="col-sm-6"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="smartMode" checked></div>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </form>
            </div>

            <div role="tabpanel" class="tab-pane" id="commandtab">
                <br>
                <div class="table-responsive">
                    <table id="table_cmd" class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
                                <th style="min-width:200px;width:350px;">{{Nom}}</th>
                                <th style="width:130px;">{{Type}}</th>
                                <th>{{Etat}}</th>
                                <th style="min-width:260px;width:400px;">{{Options}}</th>
                                <th style="min-width:80px;width:180px;">{{Actions}}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include_file('desktop', 'zendure', 'js', 'zendure');
include_file('core', 'plugin.template', 'js');
?>
