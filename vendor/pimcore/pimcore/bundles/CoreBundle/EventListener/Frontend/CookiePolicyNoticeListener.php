<?php
/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 * @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace Pimcore\Bundle\CoreBundle\EventListener\Frontend;

use Pimcore\Bundle\CoreBundle\EventListener\Traits\PimcoreContextAwareTrait;
use Pimcore\Bundle\CoreBundle\EventListener\Traits\ResponseInjectionTrait;
use Pimcore\Config;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @deprecated
 */
class CookiePolicyNoticeListener
{
    use ResponseInjectionTrait;
    use PimcoreContextAwareTrait;

    /**
     * @var bool
     */
    protected $enabled = true;

    /**
     * @var string
     */
    protected $templateCode = null;

    /**
     * @var KernelInterface
     */
    protected $kernel;

    /**
     * @var Translator
     */
    protected $translator;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @param KernelInterface $kernel
     */
    public function __construct(KernelInterface $kernel, Config $config)
    {
        $this->kernel = $kernel;
        $this->config = $config;
    }

    /**
     * @return bool
     */
    public function disable()
    {
        $this->enabled = false;

        return true;
    }

    /**
     * @return bool
     */
    public function enable()
    {
        $this->enabled = true;

        return true;
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @param string $code
     */
    public function setTemplateCode($code)
    {
        $this->templateCode = $code;
    }

    /**
     * @return string
     */
    public function getTemplateCode()
    {
        return $this->templateCode;
    }

    /**
     * @param string $path
     */
    public function loadTemplateFromResource($path)
    {
        $templateFile = $this->kernel->locateResource($path);
        if (file_exists($templateFile)) {
            $this->setTemplateCode(file_get_contents($templateFile));
        }
    }

    /**
     * @return Translator
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    /**
     * @param Translator $translator
     */
    public function setTranslator($translator)
    {
        $this->translator = $translator;
    }

    /**
     * @param FilterResponseEvent $event
     */
    public function onKernelResponse(FilterResponseEvent $event)
    {
        $request = $event->getRequest();

        if (!$event->isMasterRequest()) {
            return;
        }

        if (!$this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_DEFAULT)) {
            return;
        }

        $response = $event->getResponse();
        $locale = $request->getLocale();

        if ($this->enabled && $this->config['general']['show_cookie_notice'] && \Pimcore\Tool::useFrontendOutputFilters()) {
            if ($event->isMasterRequest() && $this->isHtmlResponse($response)) {
                $template = $this->getTemplateCode();

                // cleanup code
                $template = preg_replace('/[\r\n\t]+/', ' ', $template); //remove new lines, spaces, tabs
                $template = preg_replace('/>[\s]+</', '><', $template); //remove new lines, spaces, tabs
                $template = preg_replace('/[\s]+/', ' ', $template); //remove new lines, spaces, tabs

                $translations = $this->getTranslations($locale);

                foreach ($translations as $key => &$value) {
                    $value = htmlentities($value, ENT_COMPAT, 'UTF-8');
                    $template = str_replace('%' . $key . '%', $value, $template);
                }

                $linkContent = '';
                if (array_key_exists('linkTarget', $translations)) {
                    $linkContent = '<a href="' . $translations['linkTarget'] . '" aria-label="' . $translations['linkText'] . '" data-content="' . $translations['linkText'] . '"></a>';
                }
                $template = str_replace('%link%', $linkContent, $template);

                $templateCode = json_encode($template);

                $code = '
                        <script>
                            (function () {
                                var ls = window["localStorage"];
                                if(ls && !ls.getItem("pc-cookie-accepted")) {

                                    var code = ' . $templateCode . ';
                                    var ci = window.setInterval(function () {
                                        if(document.body) {
                                            clearInterval(ci);
                                            document.body.insertAdjacentHTML("beforeend", code);

                                            document.getElementById("pc-button").onclick = function () {
                                                document.getElementById("pc-cookie-notice").style.display = "none";
                                                ls.setItem("pc-cookie-accepted", "true");
                                            };
                                        }
                                    }, 100);
                                }
                            })();
                        </script>
                    ';

                $content = $response->getContent();

                // search for the end <head> tag, and insert the google analytics code before
                // this method is much faster than using simple_html_dom and uses less memory
                $headEndPosition = stripos($content, '</head>');
                if ($headEndPosition !== false) {
                    $content = substr_replace($content, $code . '</head>', $headEndPosition, 7);
                }

                $response->setContent($content);
            }
        }
    }

    /**
     * @param string $locale
     *
     * @return array
     */
    protected function getTranslations($locale)
    {

        // most common translations
        $defaultTranslations = [
            'text' => [
                'en' => 'Cookies help us deliver our services. By using our services, you agree to our use of cookies.',
                'de' => 'Cookies helfen uns bei der Bereitstellung unserer Dienste. Durch die Nutzung unserer Dienste erkl??ren Sie sich mit dem Einsatz von Cookies einverstanden.',
                'it' => "I cookie ci aiutano a fornire i nostri servizi. Utilizzando tali servizi, accetti l'utilizzo dei cookie da parte.",
                'fr' => "Les cookies assurent le bon fonctionnement de nos services. En utilisant ces derniers, vous acceptez l'utilisation des cookies.",
                'nl' => 'Cookies helpen ons onze services te leveren. Door onze services te gebruiken, geef je aan akkoord te gaan met ons gebruik van cookies.',
                'es' => 'Las cookies nos ayudan a ofrecer nuestros servicios. Al utilizarlos, aceptas que usemos cookies.',
                'zh' => 'Cookie ????????????????????????????????????????????????????????????????????????????????? Cookie',
                'no' => 'Informasjonskapsler hjelper oss med ?? levere tjenestene vi tilbyr. Ved ?? benytte deg av tjenestene v??re, godtar du bruken av informasjonskapsler.',
                'hu' => 'A cookie-k seg??tenek minket a szolg??ltat??sny??jt??sban. Szolg??ltat??saink haszn??lat??val j??v??hagyja, hogy cookie-kat haszn??ljunk.',
                'sv' => 'Vi tar hj??lp av cookies f??r att tillhandah??lla v??ra tj??nster. Genom att anv??nda v??ra tj??nster godk??nner du att vi anv??nder cookies.',
                'fi' => 'Ev??steet auttavat meit?? palveluidemme tarjoamisessa. K??ytt??m??ll?? palveluitamme hyv??ksyt ev??steiden k??yt??n.',
                'da' => 'Cookies hj??lper os med at levere vores tjenester. Ved at bruge vores tjenester accepterer du vores brug af cookies.',
                'pl' => 'Nasze us??ugi wymagaj?? plik??w cookie. Korzystaj??c z nich, zgadzasz si?? na u??ywanie przez nas tych plik??w.',
                'cs' => 'P??i poskytov??n?? slu??eb n??m pom??haj?? soubory cookie. Pou????v??n??m na??ich slu??eb vyjad??ujete souhlas s na????m pou????v??n??m soubor?? cookie',
                'sk' => 'S??bory cookie n??m pom??haj?? pri poskytovan?? na??ich slu??ieb. Pou????van??m na??ich slu??ieb vyjadrujete s??hlas s pou????van??m s??borov cookie.',
                'pt' => 'Os cookies nos ajudam a oferecer nossos servi??os. Ao usar nossos servi??os, voc?? concorda com nosso uso dos cookies.',
                'hr' => 'Kola??i??i nam poma??u pru??ati usluge. Upotrebom na??ih usluga prihva??ate na??u upotrebu kola??i??a.',
                'sl' => 'Pi??kotki omogo??ajo, da vam ponudimo svoje storitve. Z uporabo teh storitev se strinjate z na??o uporabo pi??kotkov.',
                'sr' => '???????????????? ?????? ???????????? ???? ?????????????? ????????????. ???????????????????? ???????????? ???????????????????? ???????? ???????????????? ????????????????.',
                'ru' => '?????????????????? ???????? ??????????????, ???? ???????????????????????? ???? ???????? ?????????????????????????? ???????????? cookie. ?????? ???????????????????? ?????? ?????????????????????? ???????????????????????????????? ?????????? ????????????????.',
                'bg' => '???????????????????????????? ???? ?????????????? ???? ???????????????????????? ???????????????? ????. ?? ???????????????????????? ???? ???????????????? ???????????????????? ???? ???????????????????????????? ???? ???????? ????????????.',
                'et' => 'K??psised aitavad meil teenuseid pakkuda. Teenuste kasutamisel n??ustute k??psiste kasutamisega.',
                'el' => '???? cookie ?????? ?????????????? ???? ?????????????????????? ?????? ?????????????????? ??????. ?????????????????????????????? ?????? ?????????????????? ??????, ???????????????????? ?????? ?????? ???????????? ?????? ?????????? ?????? cookie.',
                'lv' => 'M??su pakalpojumos tiek izmantoti s??kfaili. Lietojot m??su pakalpojumus, j??s piekr??tat s??kfailu izmanto??anai.',
                'lt' => 'Slapukai naudingi mums, kad gal??tume teikti paslaugas. Naudodamiesi paslaugomis, sutinkate, kad mes galime naudoti slapukus.',
                'ro' => 'Cookie-urile ne ajut?? s?? v?? oferim serviciile noastre. Prin utilizarea serviciilor, accepta??i modul ??n care utiliz??m cookie-urile.',
            ],
            'linkText' => [
                'en' => 'Learn more',
                'de' => 'Weitere Informationen',
                'it' => 'Ulteriori informazioni',
                'fr' => 'En savoir plus',
                'nl' => 'Meer informatie',
                'es' => 'M??s informaci??n',
                'zh' => '????????????',
                'no' => 'Finn ut mer',
                'hu' => 'Tov??bbi inform??ci??',
                'sv' => 'L??s mer',
                'fi' => 'Lis??tietoja',
                'da' => 'F?? flere oplysninger',
                'pl' => 'Wi??cej informacji',
                'cs' => 'Dal???? informace',
                'sk' => '??al??ie inform??cie',
                'pt' => 'Saiba mais',
                'hr' => 'Saznajte vi??e',
                'sl' => 'Ve?? o tem',
                'sr' => '???????????????? ????????',
                'ru' => '??????????????????...',
                'bg' => '?????????????? ????????????',
                'et' => 'Lisateave',
                'el' => '???????????? ??????????????????????',
                'lv' => 'Uzziniet vair??k',
                'lt' => 'Su??inoti daugiau',
                'ro' => 'Afla??i mai multe',
            ],
            'ok' => [
                'en' => 'OK',
                'de' => 'Ok',
                'it' => 'Ho capito',
                'fr' => "J'ai compris",
                'nl' => 'Ik snap het',
                'es' => 'De acuerdo',
                'zh' => '?????????',
                'no' => 'Greit',
                'hu' => 'Rendben',
                'sv' => 'Uppfattat',
                'fi' => 'Selv??',
                'da' => 'Forst??et',
                'pl' => 'OK',
                'cs' => 'OK',
                'sk' => 'Rozumiem',
                'pt' => 'Entendi',
                'hr' => 'Shva??am',
                'sl' => 'V redu',
                'sr' => '????????',
                'ru' => 'OK',
                'bg' => '??????????????',
                'et' => 'Selge',
                'el' => '???? ????????????????',
                'lv' => 'Sapratu',
                'lt' => 'Supratau',
                'ro' => 'Am ??n??eles',
            ],
        ];

        $translations = [];

        if ($this->getTranslator()) {
            foreach (['text', 'linkText', 'ok', 'linkTarget'] as $key) {
                $translationKey = 'cookie-policy-' . $key;
                $translation = $this->getTranslator()->trans($translationKey);
                if ($translation != $translationKey) {
                    $translations[$key] = $translation;
                }
            }
        }

        $language = 'en'; // default language
        if ($locale) {
            $languagePart = \Locale::getPrimaryLanguage($locale);
            if (array_key_exists($languagePart, $defaultTranslations['text'])) {
                $language = $languagePart;
            }
        }

        // set defaults in en
        foreach ($defaultTranslations as $key => $values) {
            if (!array_key_exists($key, $translations)) {
                $translations[$key] = $values[$language];
            }
        }

        return $translations;
    }
}
