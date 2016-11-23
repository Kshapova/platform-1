<?php

namespace Oro\Bundle\ImportExportBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use Oro\Bundle\ImportExportBundle\Exception\InvalidArgumentException;
use Oro\Bundle\ImportExportBundle\Form\Model\ImportData;
use Oro\Bundle\ImportExportBundle\Form\Model\ExportData;
use Oro\Bundle\ImportExportBundle\Form\Type\ImportType;
use Oro\Bundle\ImportExportBundle\Job\JobExecutor;
use Oro\Bundle\ImportExportBundle\Handler\ExportHandler;
use Oro\Bundle\ImportExportBundle\Handler\HttpImportHandler;
use Oro\Bundle\ImportExportBundle\Processor\ProcessorRegistry;
use Oro\Bundle\ImportExportBundle\Async\Topics;
use Oro\Bundle\SecurityBundle\Annotation\AclAncestor;
use Oro\Bundle\SecurityBundle\SecurityFacade;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;

class ImportExportController extends Controller
{
    /**
     * Take uploaded file and move it to temp dir
     *
     * @Route("/import", name="oro_importexport_import_form")
     * @AclAncestor("oro_importexport_import")
     * @Template("OroImportExportBundle:ImportExport:importForm.html.twig")
     *
     * @param Request $request
     *
     * @return array
     */
    public function importFormAction(Request $request)
    {
        $entityName = $request->get('entity');
        $importJob = $request->get('importJob');
        $importValidateJob = $request->get('importValidateJob');

        $importForm = $this->getImportForm($entityName);

        if ($request->isMethod('POST')) {
            $importForm->submit($request);

            if ($importForm->isValid()) {
                /** @var ImportData $data */
                $data           = $importForm->getData();
                $file           = $data->getFile();
                $processorAlias = $data->getProcessorAlias();

                $this->getImportHandler()->saveImportingFile($file, $processorAlias, 'csv');

                return $this->forward(
                    'OroImportExportBundle:ImportExport:importValidate',
                    ['processorAlias' => $processorAlias],
                    $request->query->all()
                );
            }
        }

        return [
            'entityName' => $entityName,
            'form' => $importForm->createView(),
            'options' => $this->getOptionsFromRequest(),
            'importJob' => $importJob,
            'importValidateJob' => $importValidateJob
        ];
    }

    /**
     * @param string $entityName
     * @return FormInterface
     */
    protected function getImportForm($entityName)
    {
        return $this->createForm(ImportType::NAME, null, ['entityName' => $entityName]);
    }

    /**
     * Validate import data
     *
     * @Route("/import/validate/{processorAlias}", name="oro_importexport_import_validate")
     * @AclAncestor("oro_importexport_import")
     * @Template("OroImportExportBundle:ImportExport:importValidate.html.twig")
     *
     * @param Request $request
     * @param string $processorAlias
     *
     * @return array
     */
    public function importValidateAction(Request $request, $processorAlias)
    {
        $processorRegistry = $this->get('oro_importexport.processor.registry');
        $entityName        = $processorRegistry
            ->getProcessorEntityName(ProcessorRegistry::TYPE_IMPORT_VALIDATION, $processorAlias);
        $existingAliases   = $processorRegistry
            ->getProcessorAliasesByEntity(ProcessorRegistry::TYPE_IMPORT_VALIDATION, $entityName);

        $jobName = $request->get('importValidateJob', JobExecutor::JOB_VALIDATE_IMPORT_FROM_CSV);
        $result = $this->getImportHandler()->handleImportValidation(
            $jobName,
            $processorAlias,
            'csv',
            null,
            $this->getOptionsFromRequest()
        );
        $result['showStrategy'] = count($existingAliases) > 1;
        $result['importJob'] = $request->get('importJob');

        return $result;
    }

    /**
     * @Route("/import/process/{processorAlias}", name="oro_importexport_import_process")
     * @AclAncestor("oro_importexport_export")
     *
     * @param string $processorAlias
     *
     * @return JsonResponse
     */
    public function importProcessAction($processorAlias)
    {
        $jobName = $this->getRequest()->get('importJob', JobExecutor::JOB_IMPORT_FROM_CSV);
        $result  = $this->getImportHandler()->handleImport(
            $jobName,
            $processorAlias,
            'csv',
            null,
            $this->getOptionsFromRequest()
        );

        return new JsonResponse($result);
    }

    /**
     * @Route("/export/instant/{processorAlias}", name="oro_importexport_export_instant")
     * @AclAncestor("oro_importexport_export")
     *
     * @param string $processorAlias
     * @param Request $request
     * @return Response
     */
    public function instantExportAction($processorAlias, Request $request)
    {
        $jobName = $request->get('exportJob', JobExecutor::JOB_EXPORT_TO_CSV);
        $filePrefix = $request->get('filePrefix', null);
        $options = array_merge(
            $this->getOptionsFromRequest($request),
            ['organization' => (string) $this->getSecurityFacade()->getOrganization()]
        );

        $this->getMessageProducer()->send(Topics::EXPORT, [
            'jobName' => $jobName,
            'processorAlias' => $processorAlias,
            'outputFilePrefix' => $filePrefix,
            'options' => $options,
            'userId' => $this->getUser()->getId(),
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/export/config", name="oro_importexport_export_config")
     * @AclAncestor("oro_importexport_export")
     * @Template("OroImportExportBundle:ImportExport:configurableExport.html.twig")
     *
     * @param Request $request
     *
     * @return array|Response
     */
    public function configurableExportAction(Request $request)
    {
        $entityName = $request->get('entity');

        $exportForm = $this->createForm('oro_importexport_export', null, ['entityName' => $entityName]);

        if ($request->isMethod('POST')) {
            $exportForm->submit($request);

            if ($exportForm->isValid()) {
                /** @var ExportData $data */
                $data = $exportForm->getData();

                return $this->forward(
                    'OroImportExportBundle:ImportExport:instantExport',
                    [
                        'processorAlias' => $data->getProcessorAlias(),
                        'request' => $request
                    ]
                );
            }
        }

        return [
            'entityName' => $entityName,
            'form' => $exportForm->createView(),
            'options' => $this->getOptionsFromRequest($request),
            'exportJob' => $request->get('exportJob')
        ];
    }

    /**
     * @Route("/export/template/config", name="oro_importexport_export_template_config")
     * @AclAncestor("oro_importexport_export")
     * @Template("OroImportExportBundle:ImportExport:configurableTemplateExport.html.twig")
     *
     * @param Request $request
     * @return array|Response
     */
    public function configurableTemplateExportAction(Request $request)
    {
        $entityName = $request->get('entity');

        $exportForm = $this->createForm('oro_importexport_export_template', null, ['entityName' => $entityName]);

        if ($request->isMethod('POST')) {
            $exportForm->submit($request);

            if ($exportForm->isValid()) {
                $data = $exportForm->getData();

                $exportTemplateResponse = $this->forward(
                    'OroImportExportBundle:ImportExport:templateExport',
                    ['processorAlias' => $data->getProcessorAlias()]
                );

                return new JsonResponse(['url' => $exportTemplateResponse->getTargetUrl()]);
            }
        }

        return [
            'entityName' => $entityName,
            'form' => $exportForm->createView(),
            'options' => $this->getOptionsFromRequest($request)
        ];
    }

    /**
     * @Route("/export/template/{processorAlias}", name="oro_importexport_export_template")
     * @AclAncestor("oro_importexport_export")
     *
     * @param string $processorAlias
     * @param Request $request
     *
     * @return Response
     */
    public function templateExportAction($processorAlias, Request $request)
    {
        $jobName = $request->get('exportTemplateJob', JobExecutor::JOB_EXPORT_TEMPLATE_TO_CSV);
        $result  = $this->getExportHandler()->getExportResult(
            $jobName,
            $processorAlias,
            ProcessorRegistry::TYPE_EXPORT_TEMPLATE,
            'csv',
            null,
            $this->getOptionsFromRequest($request)
        );

        return $this->redirect($result['url']);
    }

    /**
     * @Route("/export/download/{fileName}", name="oro_importexport_export_download")
     * @AclAncestor("oro_importexport_export")
     *
     * @param string $fileName
     *
     * @return Response
     */
    public function downloadExportResultAction($fileName)
    {
        return $this->getExportHandler()->handleDownloadExportResult($fileName);
    }

    /**
     * @Route("/import_export/error/{jobCode}.log", name="oro_importexport_error_log")
     *
     * @param string $jobCode
     * @return Response
     * @throws AccessDeniedException
     */
    public function errorLogAction($jobCode)
    {
        $securityFacade = $this->get('oro_security.security_facade');
        if (!$securityFacade->isGranted('oro_importexport_import') &&
            !$securityFacade->isGranted('oro_importexport_export')
        ) {
            throw new AccessDeniedException('Insufficient permission');
        }

        $jobExecutor = $this->getJobExecutor();
        $errors      = array_merge(
            $jobExecutor->getJobFailureExceptions($jobCode),
            $jobExecutor->getJobErrors($jobCode)
        );
        $content     = implode("\r\n", $errors);

        return new Response($content, 200, ['Content-Type' => 'text/x-log']);
    }

    /**
     * @return HttpImportHandler
     */
    protected function getImportHandler()
    {
        return $this->get('oro_importexport.handler.import.http');
    }

    /**
     * @return ExportHandler
     */
    protected function getExportHandler()
    {
        return $this->get('oro_importexport.handler.export');
    }

    /**
     * @return JobExecutor
     */
    protected function getJobExecutor()
    {
        return $this->get('oro_importexport.job_executor');
    }

    /**
     * @param Request $request
     *
     * @return array
     */
    protected function getOptionsFromRequest(Request $request)
    {
        $options = $request->get('options', []);

        if (!is_array($options)) {
            throw new InvalidArgumentException('Request parameter "options" must be array.');
        }

        return $options;
    }

    /**
     * @return MessageProducerInterface
     */
    protected function getMessageProducer()
    {
        return $this->get('oro_message_queue.client.message_producer');
    }

    /**
     * @return SecurityFacade
     */
    protected function getSecurityFacade()
    {
        return $this->get('oro_security.security_facade');
    }
}
