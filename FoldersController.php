<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\SearchFormType;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Form\Type\AnalyzeFormType;

/**
 */
class FoldersController extends Controller
{
    /**
     * @param integer $page
     * @param boolean $done
     * @param string $phrase
     * @param Request $request
     * @return array
     *
     * @Template
     */
    public function indexAction($page, $done, $phrase, Request $request)
    {
        $limit = (int) $this->container->getParameter('results-per-page') ?: 25;
        $threshold = (int) $this->container->getParameter('query-unique-threshold') ?: 0;
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $limit;
        $done = $done ?: null; //show only done or all

        $form = $this->createForm(new SearchFormType(), null, array(
            'action' => $this->generateUrl('folders'),
            'method' => 'GET',
        ));
        $form->handleRequest($request);
        if ($form->isValid()) {
            $data = $form->getData();
            $phrase = $data['query'];
        }

        $repository = $this->get('FolderRepository'); /* @var $repository \Superhost\Repository\FolderRepository */
        $total = $repository->countFolders($done, $threshold, $phrase);
        $folders = $repository->fetchFolders($limit, $offset, $done, $threshold, $phrase);

        $paginator  = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            range(1, $total), //fake source for paginator
            $page,
            $limit
        );

        return array(
            'folders' => $folders,
            'done' => $done,
            'page' => $page,
            'pagination' => $pagination,
            'form' => $form->createView(),
            'phrase' => $phrase,
        );
    }

    /**
     * @param integer $folder
     * @param integer $page
     * @param boolean $done
     * @param string $phrase
     * @return array
     *
     * @Template
     */
    public function blocksAction($folder, $page, $done, $phrase, Request $request)
    {
        $repository = $this->get('FolderRepository'); /* @var $repository \Superhost\Repository\FolderRepository */
        $folder = $repository->fetchFolder($folder);

        $threshold = (int) $this->container->getParameter('query-unique-threshold') ?: 0;
        $limit = (int) $this->container->getParameter('results-per-page') ?: 25;
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * $limit;
        $fetchDone = $done ?: null; //show only done or all

        $total = $repository->countBlocks($folder->id, $fetchDone, $phrase);
        $blocks = $repository->fetchBlocks($folder->id, $limit, $offset, $fetchDone, $phrase);

        $paginator  = $this->get('knp_paginator');
        $pagination = $paginator->paginate(
            range(1, $total), //fake source for paginator
            $page,
            $limit
        );

        $form = $this->createForm(new AnalyzeFormType(), $blocks);

        $form->handleRequest($request);
        if ($form->isValid()) {
            $data = $form->getData();

            $analyze = array();
            foreach ($blocks as $block) {
                $analyze[$block->id] = in_array($block->id, $data['analyze']); //determine checkbox status
            }
            $repository->markForAnalysis($folder->id, $analyze);

            return $this->redirectToRoute('folders_blocks', array(
                'folder' => $folder->id,
                'page' => $page,
                'done' => $done,
                'phrase' => $phrase,
            ));
        }

        return array(
            'folder' => $folder,
            'threshold' => $threshold,
            'done' => $done,
            'page' => $page,
            'blocks' => $blocks,
            'pagination' => $pagination,
            'phrase' => $phrase,
            'form' => $form->createView(),
        );
    }

}