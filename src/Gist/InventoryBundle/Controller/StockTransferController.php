<?php

namespace Gist\InventoryBundle\Controller;

use Gist\TemplateBundle\Model\CrudController;
use Gist\InventoryBundle\Entity\StockTransfer;
use Gist\InventoryBundle\Entity\StockTransferEntry;
use Gist\CoreBundle\Template\Controller\TrackCreate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use DateTime;


class StockTransferController extends CrudController
{
    use TrackCreate;
    public function __construct()
    {
        $this->route_prefix = 'gist_inv_stock_transfer';
        $this->title = 'Stock Transfer';

        $this->list_title = 'Stock Transfer';
        $this->list_type = 'dynamic';
        $this->repo = "GistInventoryBundle:StockTransfer";
    }

    public function indexAction()
    {
        $this->checkAccess($this->route_prefix . '.view');

        $this->hookPreAction();
        $gl = $this->setupGridLoader();

        $params = $this->getViewParams('List', 'gist_inv_stock_transfer_index');

        $date_from = new DateTime();
        $date_to = new DateTime();
        $date_from->format("Y-m-d");
        $date_to->format("Y-m-d");

        $this->padFormParams($params, $date_from, $date_to);
        $twig_file = 'GistInventoryBundle:StockTransfer:index.html.twig';


        $params['list_title'] = $this->list_title;
        $params['grid_cols'] = $gl->getColumns();
        return $this->render($twig_file, $params);
    }

    public function editRollbackFormAction($id)
    {
        $this->checkAccess($this->route_prefix . '.view');

        $this->hookPreAction();
        $em = $this->getDoctrine()->getManager();
        $obj = $em->getRepository($this->repo)->find($id);

        $session = $this->getRequest()->getSession();
        $session->set('csrf_token', md5(uniqid()));

        $params = $this->getViewParams('Edit');
        $params['object'] = $obj;
        $params['o_label'] = $this->getObjectLabel($obj);

        // check if we have access to form
        $params['readonly'] = !$this->getUser()->hasAccess($this->route_prefix . '.edit');

        $this->padFormParams($params, $obj);

        if ($obj->getStatus() == 'processed') {

        }
        $params['main_status'] = '';

        return $this->render('GistTemplateBundle:Object:edit.html.twig', $params);
    }

    public function callbackGrid($id)
    {
        $params = array(
            'id' => $id,
            'route_edit' => $this->getRouteGen()->getEdit(),
            'route_delete' => $this->getRouteGen()->getDelete(),
            'prefix' => $this->route_prefix,
        );

        $this->padGridParams($params, $id);

        $engine = $this->get('templating');
        return $engine->render(
            'GistInventoryBundle:StockTransfer:action.html.twig',
            $params
        );
    }

    protected function getObjectLabel($obj)
    {
        if ($obj == null)
        {
            return '';
        }
        return $obj->getID();
    }

    protected function newBaseClass()
    {
        return new StockTransfer();
    }

    protected function getGridJoins()
    {
        $grid = $this->get('gist_grid');
        return array(
            $grid->newJoin('d_inv','destination_inv_account','getDestination'),
            $grid->newJoin('s_inv','source_inv_account','getSource'),
        );
    }


    protected function getGridColumns()
    {
        $grid = $this->get('gist_grid');
        return array(
            $grid->newColumn('ID','getID','id'),
            $grid->newColumn('Status','getStatusFMTD','status'),
            $grid->newColumn('Source','getName','name','s_inv'),
            $grid->newColumn('Destination','getName','name','d_inv'),
        );
    }

    protected function padFormParams(&$params, $object = NULL)
    {
        $em = $this->getDoctrine()->getManager();
        $um = $this->get('gist_user');
        $params['user_opts'] = $um->getUserFullNameOptions();
        $inv = $this->get('gist_inventory');
        $params['wh_opts'] = array('-1'=>'-- Select Location --') + array('0'=>'Main Warehouse') + $inv->getPOSLocationOptions();
        $params['item_opts'] = array('000'=>'-- Select Product --') + $inv->getProductOptionsTransfer();
        $params['main_status'] = '';
//        if (count($object) > 12) {
//            $params['main_status'] = $object->getStatus();
//        }

        $filter = array();
        $categories = $em
            ->getRepository('GistInventoryBundle:ProductCategory')
            ->findBy(
                $filter,
                array('name' => 'ASC')
            );

        $cat_opts = array();
        $cat_opts[''] = 'All';
        foreach ($categories as $category)
            $cat_opts[$category->getID()] = $category->getName();

        $params['cat_opts'] = $cat_opts;

        return $params;
    }

    public function addSubmitAction()
    {
        $this->checkAccess($this->route_prefix . '.add');
        $data = $this->getRequest()->request->all();

        $this->hookPreAction();
        try
        {
            $obj = $this->newBaseClass();
            $this->add($obj);

            $this->addFlash('success', $this->title . ' added successfully.');

            if ($data['sp_flag'] == 'true') {
                return $this->redirect($this->generateUrl('gist_inv_stock_transfer_print',array('id'=>$obj->getID())));
            } else {
                if($this->submit_redirect){
                    return $this->redirect($this->generateUrl($this->getRouteGen()->getList()));
                }else{
                    return $this->redirect($this->generateUrl($this->getRouteGen()->getEdit(),array('id'=>$obj->getID())).$this->url_append);
                }
            }
        }
        catch (ValidationException $e)
        {
            $this->addFlash('error', 'Database error occured. Possible duplicate.'.$e);
            return $this->addError($obj);
        }
        catch (DBALException $e)
        {
            $this->addFlash('error', 'Database error occured. Possible duplicate.'.$e);
            error_log($e->getMessage());
            return $this->addError($obj);
        }
    }

    protected function add($obj)
    {
        $em = $this->getDoctrine()->getManager();
        $data = $this->getRequest()->request->all();

        $this->validate($data, 'add');
        $this->update($obj, $data, true);

        $em->persist($obj);
        $em->flush();
        $this->hookPostSave($obj,true);

        $odata = $obj->toData();
        $this->logAdd($odata);

    }

    protected function update($o, $data, $is_new = false)
    {
        $em = $this->getDoctrine()->getManager();
        $inv_stock_transfer = $this->get('gist_inventory_stock_transfer');
        if (isset($data['selected_user'])) {
            $user = $em->getRepository('GistUserBundle:User')->findOneBy(array('id'=>$data['selected_user']));
        } else {
            $user = $this->getUser();
        }

        if ($is_new || !isset($data['status'])) {

            $entries = $inv_stock_transfer->saveNewForm($o, $data, $user);
            return $entries;

        } else {

            $inv_stock_transfer->updateForm($o, $data, $user);

        }
    }

    public function printPDFAction($id)
    {
        $twig = "GistInventoryBundle:StockTransfer:print.html.twig";
        $data = $this->getOutputData($id);
        $params['emp'] = null;
        $params['dept'] = null;
        $params['all'] = $data;
        $pdf = $this->get('gist_pdf');
        $pdf->newPdf('A4');
        $html = $this->render($twig, $params);
        return $pdf->printPdf($html->getContent());
    }

    private function getOutputData($id)
    {
        $em = $this->getDoctrine()->getManager();

        $query = $em->createQueryBuilder();
        $query->from('GistInventoryBundle:StockTransfer', 'o');
        $query->andwhere("o.id = '".$id."'");

        $data = $query->select('o')
            ->getQuery()
            ->getResult();

        return $data;
    }

    /**
     *
     * Function for POS to fetch stock transfer records
     *
     * @param $pos_loc_id
     * @return JsonResponse
     */
    public function getSentFromPOSAction($pos_loc_id)
    {
        header("Access-Control-Allow-Origin: *");
        $em = $this->getDoctrine()->getManager();
        $pos_location = $em->getRepository('GistLocationBundle:POSLocations')->findOneBy(array('id'=>$pos_loc_id));
        $stock_transfers = $em->getRepository('GistInventoryBundle:StockTransfer')->findBy(array('source_inv_account'=>$pos_location->getInventoryAccount()->getID()));

        $list_opts = [];
        foreach ($stock_transfers as $p) {
            $list_opts[] = array(
                'id'=>$p->getID(),
                'source'=> $p->getSource()->getName(),
                'destination'=> $p->getDestination()->getName(),
                'date_create'=> $p->getDateCreateFormatted(),
                'status'=> ucfirst($p->getStatus()),
            );
        }

        return new JsonResponse($list_opts);
    }

    /**
     *
     * Function for POS to fetch stock transfer records
     *
     * @param $pos_loc_id
     * @return JsonResponse
     */
    public function getSentToPOSAction($pos_loc_id)
    {
        header("Access-Control-Allow-Origin: *");
        $em = $this->getDoctrine()->getManager();
        $pos_location = $em->getRepository('GistLocationBundle:POSLocations')->findOneBy(array('id'=>$pos_loc_id));
        $stock_transfers = $em->getRepository('GistInventoryBundle:StockTransfer')->findBy(array('destination_inv_account'=>$pos_location->getInventoryAccount()->getID()));

        $list_opts = [];
        foreach ($stock_transfers as $p) {
            $list_opts[] = array(
                'id'=>$p->getID(),
                'source'=> $p->getSource()->getName(),
                'destination'=> $p->getDestination()->getName(),
                'date_create'=> $p->getDateCreateFormatted(),
                'status'=> ucfirst($p->getStatus()),
            );
        }

        return new JsonResponse($list_opts);
    }

    /**
     *
     * Function for POS to fetch location options
     *
     * @param $pos_loc_id
     * @return JsonResponse
     */
    public function getLocationOptionsAction($pos_loc_id)
    {
        header("Access-Control-Allow-Origin: *");
        $em = $this->getDoctrine()->getManager();

        $inv = $this->get('gist_inventory');
        $list_opts = array('-1'=>'-- Select Location --') + array('0'=>'Main Warehouse') + $inv->getPOSLocationTransferOptions();
        unset($list_opts[$pos_loc_id]);
        return new JsonResponse($list_opts);
    }

    /**
     *
     * Function for POS to fetch stock transfer form data
     *
     * @param $id
     * @param $pos_loc_id
     * @return JsonResponse
     */
    public function getPOSFormDataAction($id, $pos_loc_id)
    {
        header("Access-Control-Allow-Origin: *");
        $invStock = $this->get('gist_inventory_stock_transfer');
        $params = $invStock->getPOSFormData(null, $id, $pos_loc_id);

        return new JsonResponse($params);
    }

    /**
     *
     * Function for POS to fetch stock transfer form data
     *
     * @param $id
     * @return JsonResponse
     * @internal param $pos_loc_id
     */
    public function getPOSFormDataEntriesAction($id)
    {
        header("Access-Control-Allow-Origin: *");
        $invStock = $this->get('gist_inventory_stock_transfer');
        $params = $invStock->getPOSFormDataEntries(null, $id);

        return new JsonResponse($params);
    }

    /**
     *
     * Function for POS to update stock transfer status
     *
     * @param $id
     * @return JsonResponse
     * @internal param $pos_loc_id
     */
    public function updatePOSStockTransferAction($id, $userId, $status, $entries)
    {
        header("Access-Control-Allow-Origin: *");
        $em = $this->getDoctrine()->getManager();
        $st = $em->getRepository('GistInventoryBundle:StockTransfer')->findOneBy(array('id'=>$id));
        $user = $em->getRepository('GistUserBundle:User')->findOneBy(array('id'=>$userId));

        $st->setStatus($status);

        if($status == 'processed') {
            $st->setProcessedUser($user);
            $st->setDateProcessed(new DateTime());

        } elseif ($status == 'delivered') {
            $st->setDeliverUser($user);
            $st->setDateDelivered(new DateTime());
        } elseif ($status == 'arrived') {
            $st->setReceivingUser($user);
            $st->setDateReceived(new DateTime());
            parse_str($entries, $entriesParsed);

            foreach ($entriesParsed as $e) {
                if (isset($e['st_entry'])) {
                    $entry_id = $e['st_entry'];
                    $qty = $e['received_quantity'];

                    // entry
                    $entry = $em->getRepository('GistInventoryBundle:StockTransferEntry')->findOneBy(array('id' => $entry_id));
                    $entry->setReceivedQuantity($qty);

                    $em->persist($entry);
                    $em->flush();

                    //$entries[] = $entry;
                }
            }

        } else {
            // for overwrite scenario
            $list_opts[] = array(
                'status'=>'failed'
            );

            return new JsonResponse($list_opts);
        }

        $em->persist($st);
        $em->flush();

        $list_opts[] = array(
            'status'=>'success'
        );

        return new JsonResponse($list_opts);
    }

    /**
     *
     * Function for POS to add stock transfer
     *
     * @param $src
     * @param $dest
     * @param $user
     * @param $description
     * @param $entries
     * @param $status
     * @param $id
     * @return JsonResponse
     * @internal param $pos_loc_id
     */
    public function addPOSStockTransferAction($src, $dest, $user, $description, $entries, $status, $id)
    {
        header("Access-Control-Allow-Origin: *");
        $em = $this->getDoctrine()->getManager();
        $inv = $this->get('gist_inventory');
        $config = $this->get('gist_configuration');
        $user = $em->getRepository('GistUserBundle:User')->findOneBy(array('id'=>$user));

        parse_str($entries, $entriesParsed);

        if ($status != 'to_update') {
            $st = new StockTransfer();
            $st->setStatus('requested');
            $st->setRequestingUser($user);

            // warehouse
            if ($src == '0') {
                $wh_src = $inv->findWarehouse($config->get('gist_main_warehouse'));
            } else {
                $wh_src = $em->getRepository('GistLocationBundle:POSLocations')->find($src);
            }

            if ($dest == '0') {
                $wh_destination = $inv->findWarehouse($config->get('gist_main_warehouse'));
            } else {
                $wh_destination = $em->getRepository('GistLocationBundle:POSLocations')->find($dest);
            }

            $st->setDescription($description);
            $st->setSource($wh_src->getInventoryAccount());
            $st->setDestination($wh_destination->getInventoryAccount());

            $em->persist($st);
            $em->flush();

            foreach ($entriesParsed as $e) {
                $prod_item_code = $e['code'];
                $qty = $e['quantity'];
                $prod = $em->getRepository('GistInventoryBundle:Product')->findOneBy(array('item_code' => $prod_item_code));
                if ($prod == null)
                    throw new ValidationException('Could not find product.');

                //from src
                $entry = new StockTransferEntry();
                $entry->setStockTransfer($st)
                    ->setProduct($prod)
                    ->setQuantity($qty);

                $em->persist($entry);
                $em->flush();
            }

            $em->persist($st);
            $em->flush();

            $list_opts[] = array(
                'status'=>'success',
                'id'=>$st->getID()
            );
        } else {
            $transferStock = $em->getRepository('GistInventoryBundle:StockTransfer')->findOneBy(array('id'=>$id));
            $existingEntries = $em->getRepository('GistInventoryBundle:StockTransferEntry')->findBy(array('stock_transfer'=>$transferStock->getID()));

            foreach ($existingEntries as $ee) {
                $em->remove($ee);
            }

            $em->flush();

            foreach ($entriesParsed as $e) {
                $prod_item_code = $e['code'];
                $qty = $e['quantity'];
                $prod = $em->getRepository('GistInventoryBundle:Product')->findOneBy(array('item_code' => $prod_item_code));
                if ($prod == null)
                    throw new ValidationException('Could not find product.');

                //from src
                $entry = new StockTransferEntry();
                $entry->setStockTransfer($transferStock)
                    ->setProduct($prod)
                    ->setQuantity($qty);

                $em->persist($entry);
                $em->flush();


                $em->persist($entry);
                $em->flush();

            }

            $list_opts[] = array(
                'status'=>'success',
                'id'=>$transferStock->getID()
            );

        }

        return new JsonResponse($list_opts);
    }
}
