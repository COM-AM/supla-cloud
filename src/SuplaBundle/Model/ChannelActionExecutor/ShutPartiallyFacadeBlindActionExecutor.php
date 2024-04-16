<?php
namespace SuplaBundle\Model\ChannelActionExecutor;

use Assert\Assert;
use Assert\Assertion;
use SuplaBundle\Entity\ActionableSubject;
use SuplaBundle\Enums\ChannelFunction;
use SuplaBundle\Enums\ChannelFunctionAction;
use SuplaBundle\Utils\StringUtils;

class ShutPartiallyFacadeBlindActionExecutor extends SingleChannelActionExecutor {
    public function getSupportedAction(): ChannelFunctionAction {
        return ChannelFunctionAction::SHUT_PARTIALLY();
    }

    public function getSupportedFunctions(): array {
        return [
            ChannelFunction::CONTROLLINGTHEFACADEBLIND(),
        ];
    }

    public function validateAndTransformActionParamsFromApi(ActionableSubject $subject, array $actionParams): array {
        Assertion::between(count($actionParams), 1, 2, 'Invalid number of parameters.');
        Assertion::allInArray(array_keys($actionParams), ['percentage', 'tilt']);
        $params = [];
        if (array_key_exists('percentage', $actionParams) && StringUtils::isNotBlank($actionParams['percentage'])) {
            [$percentage, $isDelta] = $this->extractDeltaParam($actionParams['percentage']);
            Assert::that($percentage)->numeric()->between(-100, 100);
            $params['percentage'] = intval($percentage);
            $params['percentageAsDelta'] = $isDelta;
        }
        if (array_key_exists('tilt', $actionParams) && StringUtils::isNotBlank($actionParams['tilt'])) {
            [$tilt, $isDelta] = $this->extractDeltaParam($actionParams['tilt']);
            Assert::that($tilt)->numeric()->between(-100, 100);
            $params['tilt'] = intval($tilt);
            $params['tiltAsDelta'] = $isDelta;
        }
        Assertion::true(isset($params['percentage']) || isset($params['tilt']), 'You have to set either percentage and/or tilt.');
        return array_merge(['percentageAsDelta' => 0, 'tiltAsDelta' => 0], $params);
    }

    public function execute(ActionableSubject $subject, array $actionParams = []) {
        $percentage = $actionParams['percentage'] ?? -1;
        $tilt = $actionParams['tilt'] ?? -1;
        // ACTION-SHUT-PARTIALLY:userId,deviceId,channelId,percentage,percentageAsDelta,tilt,tiltAsDelta
        $command = $subject->buildServerActionCommand('ACTION-SHUT-PARTIALLY', [
            $percentage,
            $actionParams['percentageAsDelta'] ? 1 : 0,
            $tilt,
            $actionParams['tiltAsDelta'] ? 1 : 0,
        ]);
        $this->suplaServer->executeCommand($command);
    }

    public function transformActionParamsForApi(ActionableSubject $subject, array $actionParams): array {
        $params = ['percentage' => '', 'tilt' => ''];
        if (isset($actionParams['percentage'])) {
            $params['percentage'] = strval($actionParams['percentage']);
            if ($actionParams['percentageAsDelta'] && $actionParams['percentage'] > 0) {
                $params['percentage'] = "+{$actionParams['percentage']}";
            }
        }
        if (isset($actionParams['tilt'])) {
            $params['tilt'] = strval($actionParams['tilt']);
            if ($actionParams['tiltAsDelta'] && $actionParams['tilt'] > 0) {
                $params['tilt'] = "+{$actionParams['tilt']}";
            }
        }
        return $params;
    }

    private function extractDeltaParam($param): array {
        $isDelta = false;
        if (is_string($param) && in_array($param[0], ['+', ' ', '-'])) {
            $isDelta = true;
            $param = ltrim($param, '+ ');
        }
        Assertion::numeric($param);
        $param = intval($param);
        return [$param, $isDelta || $param < 0];
    }
}
