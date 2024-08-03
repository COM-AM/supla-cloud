<?php

namespace SuplaBundle\Model\UserConfigTranslator;

use Assert\Assert;
use Assert\Assertion;
use SuplaBundle\Entity\HasUserConfig;
use SuplaBundle\Enums\ChannelFunction;
use SuplaBundle\Exception\ApiException;
use SuplaBundle\Supla\SuplaOcrClient;
use SuplaBundle\Utils\NumberUtils;

class OcrSettingsUserConfigTranslator extends UserConfigTranslator {
    use FixedRangeParamsTranslator;

    private const KEYS_TO_SYNCHRONIZE = ['photoSettings', 'decimalPoints'];
    private const MIN_PHOTO_INTERVAL_SEC = 60;

    private SuplaOcrClient $ocr;

    public function __construct(SuplaOcrClient $ocr) {
        $this->ocr = $ocr;
    }

    public function getConfig(HasUserConfig $subject): array {
        $ocrProp = $subject->getProperty('ocr');
        if ($ocrProp) {
            $ocrConfig = $subject->getUserConfigValue('ocr', []);
            $ocrConfig['availableLightingModes'] = $ocrProp['availableLightingModes'] ?? [];
            $ocrConfig['enabled'] = ($ocrConfig['photoIntervalSec'] ?? 0) > 0;
            $ocrConfig['photoIntervalSec'] = max($ocrConfig['photoIntervalSec'] ?? 0, self::MIN_PHOTO_INTERVAL_SEC);
            $decimalPoints = $ocrConfig['decimalPoints'] ?? 0;
            $ocrConfig['maximumIncrement'] = NumberUtils::maximumDecimalPrecision(
                ($ocrConfig['maximumIncrement'] ?? 0) / pow(10, $decimalPoints),
                $decimalPoints
            );
            return ['ocr' => $ocrConfig];
        } else {
            return [];
        }
    }

    public function setConfig(HasUserConfig $subject, array $config) {
        if (array_key_exists('ocr', $config) && $config['ocr']) {
            $ocrConfig = $config['ocr'];
            Assertion::isArray($ocrConfig);
            Assertion::allInArray(array_keys($ocrConfig), [
                'enabled', 'photoIntervalSec', 'lightingMode', 'lightingLevel', 'decimalPoints', 'photoSettings', 'maximumIncrement',
                'availableLightingModes',
            ]);
            if (array_key_exists('enabled', $ocrConfig)) {
                Assertion::boolean($ocrConfig['enabled']);
                if ($ocrConfig['enabled']) {
                    Assertion::keyExists($ocrConfig, 'photoIntervalSec');
                    Assertion::greaterThan($ocrConfig['photoIntervalSec'], 0);
                } else {
                    $ocrConfig['photoIntervalSec'] = 0;
                }
                unset($ocrConfig['enabled']);
            }
            if (array_key_exists('photoIntervalSec', $ocrConfig)) {
                if ($ocrConfig['photoIntervalSec']) {
                    Assert::that($ocrConfig['photoIntervalSec'], null, 'ocr.photoIntervalSec')
                        ->integer()
                        ->between(self::MIN_PHOTO_INTERVAL_SEC, 300);
                } else {
                    $ocrConfig['photoIntervalSec'] = 0;
                }
            }
            $availableLightingModes = $this->getConfig($subject)['ocr']['availableLightingModes'] ?? [];
            if ($availableLightingModes) {
                if (array_key_exists('lightingMode', $ocrConfig)) {
                    Assert::that($ocrConfig['lightingMode'], null, 'ocr.lightingMode')
                        ->string()
                        ->inArray($availableLightingModes);
                }
                if (array_key_exists('lightingLevel', $ocrConfig)) {
                    Assert::that($ocrConfig['lightingLevel'], null, 'ocr.lightingLevel')->integer()->between(1, 100);
                }
            }
            if (array_key_exists('decimalPoints', $ocrConfig)) {
                Assert::that($ocrConfig['decimalPoints'], null, 'ocr.decimalPoints')->integer()->between(0, 10);
                $subject->setUserConfigValue('impulsesPerUnit', pow(10, $ocrConfig['decimalPoints']));
                if (!array_key_exists('maximumIncrement', $ocrConfig)) {
                    $previousDecimalPoints = $subject->getUserConfigValue('ocr', [])['decimalPoints'] ?? 0;
                    $maxIncrement = $subject->getUserConfigValue('ocr', [])['maximumIncrement'] ?? 0;
                    $ocrConfig['maximumIncrement'] = $maxIncrement / pow(10, $previousDecimalPoints);
                }
            }
            if (array_key_exists('maximumIncrement', $ocrConfig)) {
                Assert::that($ocrConfig['maximumIncrement'], null, 'ocr.maximumIncrement')->numeric()->greaterOrEqualThan(0);
                $decimalPoints = $ocrConfig['decimalPoints'] ?? $subject->getUserConfigValue('ocr', [])['decimalPoints'] ?? 0;
                $ocrConfig['maximumIncrement'] *= pow(10, $decimalPoints);
                $ocrConfig['maximumIncrement'] = round($ocrConfig['maximumIncrement']);
            }
            if (array_key_exists('photoSettings', $ocrConfig)) {
                Assert::that($ocrConfig['photoSettings'], null, 'ocr.photoSettings')->isArray();
            }
            $ocrConfig = array_replace($subject->getUserConfigValue('ocr', []), $ocrConfig);
            $this->synchronizeConfigWithOcr($subject, $ocrConfig);
            $subject->setUserConfigValue('ocr', $ocrConfig);
        }
    }

    private function synchronizeConfigWithOcr(HasUserConfig $subject, $ocrConfig): void {
        $ocrConfigBefore = $subject->getUserConfigValue('ocr', []);
        $configToSynchronizeBefore = array_intersect_key($ocrConfigBefore, array_flip(self::KEYS_TO_SYNCHRONIZE));
        $configToSynchronize = array_intersect_key($ocrConfig, array_flip(self::KEYS_TO_SYNCHRONIZE));
        if ($configToSynchronize != $configToSynchronizeBefore) {
            try {
                $this->ocr->updateSettings($subject, array_intersect_key($ocrConfig, array_flip(self::KEYS_TO_SYNCHRONIZE)));
            } catch (ApiException $e) {
                Assertion::true(false, 'Cannot update OCR settings. Try again in a while.'); // i18n
            }
        }
    }

    public function supports(HasUserConfig $subject): bool {
        return in_array($subject->getFunction()->getId(), [
            ChannelFunction::IC_ELECTRICITYMETER,
            ChannelFunction::IC_GASMETER,
            ChannelFunction::IC_WATERMETER,
            ChannelFunction::IC_HEATMETER,
        ]);
    }
}
