<?php

/**
 * This file is part of richardhj/contao-ajax_reload_element.
 *
 * Copyright (c) 2016-2017 Richard Henkenjohann
 *
 * @package   richardhj/contao-ajax_reload_element
 * @author    Richard Henkenjohann <richardhenkenjohann@googlemail.com>
 * @copyright 2016-2017 Richard Henkenjohann
 * @license   https://github.com/richardhj/contao-ajax_reload_element/blob/master/LICENSE LGPL-3.0
 */

namespace Richardhj\Contao\Ajax;

use Contao\ArticleModel;
use Contao\ContentModel;
use Contao\Controller;
use Contao\Environment;
use Contao\FrontendTemplate;
use Contao\Input;
use Contao\Model;
use Contao\ModuleModel;
use Contao\Template;
use ContaoCommunityAlliance\UrlBuilder\UrlBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;


/**
 * Class AjaxReloadElement
 */
class AjaxReloadElement
{

    const TYPE_MODULE  = 'mod';
    const TYPE_CONTENT = 'ce';
    const TYPE_ARTICLE = 'art';

    /**
     * Add the html attribute to allowed elements
     *
     * @param Template $template
     */
    public function parseTemplate($template)
    {
        if (!($template instanceof FrontendTemplate) || !$template->allowAjaxReload) {
            return;
        }

        // Determine whether we have a module, a content element or an article by the vars given at this point
        $type = ('article' === $template->type)
            ? self::TYPE_ARTICLE
            : (('tl_article' === $template->ptable) ? self::TYPE_CONTENT : self::TYPE_MODULE);


        // cssID is parsed in all common templates
        // Use cssID for our attribute
        $template->cssID .= sprintf(
            ' data-ajax-reload-element="%s::%u"%s',
            $type,
            $template->id,
            ($template->ajaxReloadFormSubmit) ? ' data-ajax-reload-form-submit=""' : ''
        );
    }

    /**
     * We check for an ajax request on the getPageLayout hook, which is one of the first hooks being called. If so, and
     * the ajax request is directed to us, we send the generated module/content element as a JSON response.
     *
     * @internal param PageModel $page
     * @internal param LayoutModel $layout
     * @internal param PageRegular $pageHandler
     */
    public function processAjaxRequest()
    {
        if (false === Environment::get('isAjaxRequest')
            || !(null !== ($paramElement = Input::get('ajax_reload_element'))
                 || null !== ($paramElement = Input::post('ajax_reload_element')))
        ) {
            return;
        }

        list ($elementType, $elementId) = trimsplit('::', $paramElement);
        $error  = '';
        $return = '';

        // Remove the get parameter from the url
        $requestUrl = UrlBuilder::fromUrl(Environment::get('request'));
        $requestUrl->unsetQueryParameter('ajax_reload_element');
        Environment::set('request', $requestUrl->getUrl());

        switch ($elementType) {
            case self::TYPE_MODULE:
                /** @type Model $module */
                $module = ModuleModel::findByPk($elementId);

                if (null === $module) {
                    $error = sprintf('Could not find module ID %s', $elementId);
                    continue;
                }

                if (!$module->allowAjaxReload) {
                    $error = sprintf('Module ID %u is not allowed to fetch', $elementId);
                    continue;
                }

                $return = Controller::getFrontendModule($module);
                break;

            case self::TYPE_CONTENT:
                /** @type Model $contentElement */
                $contentElement = ContentModel::findByPk($elementId);

                if (null === $contentElement) {
                    $error = sprintf('Could not find content element ID %s', $elementId);
                    continue;
                }

                if (!$contentElement->allowAjaxReload) {
                    $error = sprintf('Content element ID %u is not allowed to fetch', $elementId);
                    continue;
                }

                $return = Controller::getContentElement($contentElement);
                break;

            case self::TYPE_ARTICLE:
                /** @type Model $article */
                $article = ArticleModel::findByPk($elementId);

                if (null === $article) {
                    $error = sprintf('Could not find article ID %s', $elementId);
                    continue;
                }

                if (!$article->allowAjaxReload) {
                    $error = sprintf('Article ID %u is not allowed to fetch', $elementId);
                    continue;
                }

                $return = Controller::getArticle($article);
                break;

            default:
                $error = 'Could not determine whether the element is a module or content element';
                break;
        }

        // Remove login error from session as it is not done in the module class anymore (see contao/core#7824)
        unset($_SESSION['LOGIN_ERROR']);

        // Replace insert tags and then re-replace the request_token tag in case a form element has been loaded via insert tag
        $return = Controller::replaceInsertTags($return, false);
        $return = str_replace(['{{request_token}}', '[{]', '[}]'], [REQUEST_TOKEN, '{{', '}}'], $return);
        $return = Controller::replaceDynamicScriptTags($return); // see contao/core#4203

        $data = [];

        if ('' !== $error) {
            $data['status'] = 'error';
            $data['error']  = $error;
        } else {
            $data['status'] = 'ok';
            $data['html']   = $return;
        }

        $response = new JsonResponse($data);
        $response->send();
        exit;
    }
}
