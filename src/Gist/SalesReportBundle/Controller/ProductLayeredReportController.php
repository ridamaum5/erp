<?php

namespace Gist\SalesReportBundle\Controller;

use Gist\TemplateBundle\Model\CrudController;
use Gist\InventoryBundle\Entity\Transaction;
use Gist\InventoryBundle\Entity\Entry;
use Gist\InventoryBundle\Entity\StockHistory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Gist\ValidationException;
use Gist\InventoryBundle\Model\InventoryException;
use Gist\TemplateBundle\Model\BaseController as Controller;
use Gist\TemplateBundle\Model\RouteGenerator as RouteGenerator;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use DateTime;

class ProductLayeredReportController extends Controller
{
    protected $repo;
    protected $base_view;
    protected $route_gen;

    public function __construct()
    {
        $this->route_prefix = 'gist_layered_sales_report_product';
        $this->title = 'Layered Report - Product';

        $this->list_title = 'Layered Report - Product';
        $this->list_type = 'static';
    }

    // FOR TOP LAYER
    public function indexAction($date_from = null, $date_to = null, $brand = null, $category = null)
    {
        $data = $this->getRequest()->request->all();


        $this->route_prefix = 'gist_layered_sales_report_product';
        $params = $this->getViewParams('List');
        $this->getControllerBase();
        $gl = $this->setupGridLoader();
        $params['grid_cols'] = $gl->getColumns();

        //PARAMS
        $params['brand'] = $brand;
        $params['category'] = $category;

        if (isset($data['date_from']) && isset($data['date_to'])) {
            $date_from = DateTime::createFromFormat('Ymd', $data['date_from']);
            $date_to = DateTime::createFromFormat('Ymd', $data['date_to']);
            $params['date_from'] = $date_from->format("m/d/Y");
            $params['date_to'] = $date_to->format("m/d/Y");
            $params['all_data'] = $this->getAllData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'));
            $params['date_from_url'] = $date_from->format("m-d-Y");
            $params['date_to_url'] = $date_to->format("m-d-Y");
        } else {
            $date_from = new DateTime();
            $date_to = new DateTime();
            $date_from_twig = $date_from->format("m/01/Y");
            $date_to_twig = $date_to->format("m/t/Y");
            $params['date_from'] = $date_from_twig;
            $params['date_to'] = $date_to_twig;
            $params['date_from_url'] = $date_from->format("m-01-Y");
            $params['date_to_url'] = $date_to->format("m-t-Y");
            $params['all_data'] = $this->getAllData($date_from->format('Y-m-01'), $date_to->format('Y-m-t'));
        }


        return $this->render('GistSalesReportBundle:ProductLayered:index.html.twig', $params);
    }

    protected function getAllData($date_from, $date_to)
    {
        $em = $this->getDoctrine()->getManager();

        $query = $em->createQueryBuilder();
        $query->from('GistPOSERPBundle:POSTransaction', 'o')
            ->where('o.date_create <= :date_to')
            ->andWhere('o.date_create >= :date_from')
            ->setParameter('date_from', $date_from)
            ->setParameter('date_to', $date_to);

        $data = $query->select('o')
            ->getQuery()
            ->getResult();

        $total_payments = 0;
        $total_cost = 0;
        $total_profit = 0;

        foreach ($data as $d) {
            $total_payments += $d->getTransactionTotal();

            foreach ($d->getItems() as $item) {
                $product = $em->getRepository('GistInventoryBundle:Product')->findOneById($item->getProductId());
                $total_cost += $product->getCost();
            }
        }

        $total_profit = $total_payments - $total_cost;

        return [
            'total_sales' => number_format($total_payments, 2, '.',','),
            'total_cost' => number_format($total_cost, 2, '.',','),
            'total_profit' => number_format($total_profit, 2, '.',','),
        ];
    }

    //FOR BRANDS/L2
    public function brandsIndexAction($date_from = null, $date_to = null, $brand = null, $category = null)
    {
        try {
            $data = $this->getRequest()->request->all();

            $this->route_prefix = 'gist_layered_sales_report_product';
            $params = $this->getViewParams('List');
            $this->getControllerBase();
            $gl = $this->setupGridLoader();
            $params['grid_cols'] = $gl->getColumns();

            //PARAMS
            $params['brand'] = $brand;
            $params['category'] = $category;

            if (DateTime::createFromFormat('m-d-Y', $date_from) !== false && DateTime::createFromFormat('m-d-Y', $date_to) !== false) {
                $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
                $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
                $params['date_from'] = $date_from->format("m/d/Y");
                $params['date_to'] = $date_to->format("m/d/Y");
                $params['brands_data'] = $this->getBrandsData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'));
                $params['date_from_url'] = $date_from->format("m-d-Y");
                $params['date_to_url'] = $date_to->format("m-d-Y");

                return $this->render('GistSalesReportBundle:ProductLayered:brands.html.twig', $params);

            } else {
                return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
            }


        } catch (Exception $e) {
            return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
        }
    }

    protected function getBrandsData($date_from, $date_to)
    {
        $em = $this->getDoctrine()->getManager();

        //get all brands
        $allBrands = $em->getRepository('GistInventoryBundle:Brand')->findAll();

        foreach ($allBrands as $brandObject) {
            //initiate totals
            $brandId = $brandObject->getID();
            $brandTotalSales = 0;
            $brandTotalCost = 0;
            $brandTransactionIds = array();

            //get all transaction items based on date filter
            $query = $em->createQueryBuilder();
            $query->from('GistPOSERPBundle:POSTransactionItem', 'o')
                ->join('GistPOSERPBundle:POSTransaction', 't', 'WITH', 't.id= o.transaction')
                ->where('o.date_create <= :date_to')
                ->andWhere('o.date_create >= :date_from')
                ->setParameter('date_from', $date_from)
                ->setParameter('date_to', $date_to);

            $transactionItems = $query->select('o')
                ->getQuery()
                ->getResult();

            //loop items and check if item's brand is the current loop's brand then add the cost
            foreach ($transactionItems as $transactionItem) {
                $product = $em->getRepository('GistInventoryBundle:Product')->findOneById($transactionItem->getProductId());
                if ($product->getBrand()->getID() == $brandId) {
                    $brandTotalCost += $product->getCost();
                    //store transaction id of item for use
                    array_push($brandTransactionIds, $transactionItem->getTransaction()->getID());
                }
            }

            //get all transactions based on date filter
            $queryTrans = $em->createQueryBuilder();
            $queryTrans->from('GistPOSERPBundle:POSTransaction', 'o')
                ->where('o.date_create <= :date_to')
                ->andWhere('o.date_create >= :date_from')
                ->setParameter('date_from', $date_from)
                ->setParameter('date_to', $date_to);

            $transactions = $queryTrans->select('o')
                ->getQuery()
                ->getResult();

            //check if transaction id is included in array created
            foreach ($transactions as $transaction) {
                if (in_array($transaction->getID(), $brandTransactionIds)) {
                    $brandTotalSales += $transaction->getTransactionTotal();
                }
            }

            $brandTotalProfit = $brandTotalSales - $brandTotalCost;

            $list_opts[] = array(
                'date_from'=>$date_from,
                'date_to'=> $date_to,
                'brand_id' => $brandObject->getID(),
                'brand_name' => $brandObject->getName(),
                'total_sales' => number_format($brandTotalSales, 2, '.',','),
                'total_cost' => number_format($brandTotalCost, 2, '.',','),
                'total_profit' => number_format($brandTotalProfit, 2, '.',','),
            );
        }

        return $list_opts;
    }

    //FOR BRANDED/L3 / SHOW BRAND CATEGORIES
    public function brandedIndexAction($date_from = null, $date_to = null, $brand = null, $category = null)
    {
        try {
            $data = $this->getRequest()->request->all();

            $this->route_prefix = 'gist_layered_sales_report_product';
            $params = $this->getViewParams('List');
            $this->getControllerBase();
            $gl = $this->setupGridLoader();
            $params['grid_cols'] = $gl->getColumns();

            //PARAMS
            $params['brand'] = $brand;
            $params['category'] = $category;

            if (DateTime::createFromFormat('m-d-Y', $date_from) !== false && DateTime::createFromFormat('m-d-Y', $date_to) !== false) {
                $date_from = DateTime::createFromFormat('m-d-Y', $date_from);
                $date_to = DateTime::createFromFormat('m-d-Y', $date_to);
                $params['date_from'] = $date_from->format("m/d/Y");
                $params['date_to'] = $date_to->format("m/d/Y");
                $params['categories_data'] = $this->getCategoriesData($date_from->format('Y-m-d'), $date_to->format('Y-m-d'),$brand);
                $params['date_from_url'] = $date_from->format("m-d-Y");
                $params['date_to_url'] = $date_to->format("m-d-Y");

                return $this->render('GistSalesReportBundle:ProductLayered:categories.html.twig', $params);

            } else {
                return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
            }


        } catch (Exception $e) {
            return $this->redirect($this->generateUrl('gist_layered_sales_report_product_index'));
        }
    }

    protected function getCategoriesData($date_from, $date_to, $brand)
    {
        $em = $this->getDoctrine()->getManager();

        //get all categories
        $allCategories = $em->getRepository('GistInventoryBundle:ProductCategory')->findAll();

        foreach ($allCategories as $categoryObject) {
            //initiate totals
            $categoryId = $categoryObject->getID();
            $totalSales = 0;
            $totalCost = 0;
            $transactionIds = array();

            //get all transaction items based on date filter
            $query = $em->createQueryBuilder();
            $query->from('GistPOSERPBundle:POSTransactionItem', 'o')
                ->join('GistPOSERPBundle:POSTransaction', 't', 'WITH', 't.id= o.transaction')
                ->where('o.date_create <= :date_to')
                ->andWhere('o.date_create >= :date_from')
                ->setParameter('date_from', $date_from)
                ->setParameter('date_to', $date_to);

            $transactionItems = $query->select('o')
                ->getQuery()
                ->getResult();

            //loop items and check if item's brand is the current loop's brand then add the cost
            foreach ($transactionItems as $transactionItem) {
                $product = $em->getRepository('GistInventoryBundle:Product')->findOneById($transactionItem->getProductId());
                if ($product->getCategory()->getID() == $categoryId && $product->getBrand()->getID() == $brand) {
                    $totalCost += $product->getCost();
                    //store transaction id of item for use
                    array_push($transactionIds, $transactionItem->getTransaction()->getID());
                }
            }

            //get all transactions based on date filter
            $queryTrans = $em->createQueryBuilder();
            $queryTrans->from('GistPOSERPBundle:POSTransaction', 'o')
                ->where('o.date_create <= :date_to')
                ->andWhere('o.date_create >= :date_from')
                ->setParameter('date_from', $date_from)
                ->setParameter('date_to', $date_to);

            $transactions = $queryTrans->select('o')
                ->getQuery()
                ->getResult();

            //check if transaction id is included in array created
            foreach ($transactions as $transaction) {
                if (in_array($transaction->getID(), $transactionIds)) {
                    $totalSales += $transaction->getTransactionTotal();
                }
            }

            $totalProfit = $totalSales - $totalCost;

            $list_opts[] = array(
                'date_from'=>$date_from,
                'date_to'=> $date_to,
                'brand_id' => $brand,
                'category_id' => $categoryObject->getID(),
                'category_name' => $categoryObject->getName(),
                'total_sales' => number_format($totalSales, 2, '.',','),
                'total_cost' => number_format($totalCost, 2, '.',','),
                'total_profit' => number_format($totalProfit, 2, '.',','),
            );
        }
        //883,590.00	500,252.90	383,337.10
        return $list_opts;
    }

    protected function getProductsData($date_from, $date_to, $brand, $category)
    {

    }

    protected function getRouteGen()
    {
        if ($this->route_gen == null)
            $this->route_gen = new RouteGenerator($this->route_prefix);

        return $this->route_gen;
    }

    protected function getViewParams($subtitle = '', $selected = null)
    {
        if ($selected == null && $this->getRouteGen()->getList() != null)
            $selected = $this->getRouteGen()->getList();

        $params = parent::getViewParams($subtitle, $selected);
        $params['route_list'] = $this->getRouteGen()->getList();
        $params['route_add'] = $this->getRouteGen()->getAdd();
        $params['route_edit'] = $this->getRouteGen()->getEdit();
        $params['route_delete'] = $this->getRouteGen()->getDelete();
        $params['route_grid'] = $this->getRouteGen()->getGrid();
        $params['list_title'] = $this->list_title;
        $params['prefix'] = $this->route_prefix;

        $params['base_view'] = $this->base_view;
        return $params;
    }

    protected function setupGridLoader($pos_loc_id = null)
    {
        $this->repo = "GistInventoryBundle:Stock";
        $data = $this->getRequest()->query->all();
        $grid = $this->get('gist_grid');

        $gloader = $grid->newLoader();
        $gloader->processParams($data)
            ->setRepository($this->repo);

        $gjoins = $this->getGridJoins();
        foreach ($gjoins as $gj)
            $gloader->addJoin($gj);

        $gcols = $this->getGridColumns($pos_loc_id);

        foreach ($gcols as $gc)
            $gloader->addColumn($gc);

        return $gloader;
    }

    protected function getGridJoins()
    {
        $grid = $this->get('gist_grid');
        return array(
            $grid->newJoin('prod','product','getProduct'),
            $grid->newJoin('inv','inv_account','getInventoryAccount'),
        );
    }


    protected function getGridColumns($pos_loc_id = null)
    {
        $grid = $this->get('gist_grid');
        return array(
            $grid->newColumn('Item Code','getItemCode','item_code', 'prod'),
            $grid->newColumn('Item Name','getID','name', 'prod', array($this,'formatProductLink')),
            $grid->newColumn('Current Stock','getQuantity','quantity', 'o')
        );
    }

    public function formatNumericLinkThreshold($number) {
        return "<div class=\"numeric\"><a style=\"text-decoration: none;\" href=\"javascript:void(0)\" class=\"change_threshold_btn\">".number_format($number, 2)."</a></div>";
    }

    public function formatProductLink($id) {
        $em = $this->getDoctrine()->getManager();
        $router = $this->get('router');
        $obj = $em->getRepository('GistInventoryBundle:Product')->find($id);
        if($obj->getID() != null)
            return "
                <input type=\"hidden\" class=\"row_prod_id\" value=\"".$obj->getID()."\">
                <a style=\"text-decoration: none;\" href=\"".$router->generate('cat_inv_prod_edit_form', array('id' => $obj->getID()))."\">".$obj->getName()."</a>
            ";
        else
            return "-";
    }

    public function formatInv($id) {
        $em = $this->getDoctrine()->getManager();
        $router = $this->get('router');
        $obj = $em->getRepository('GistInventoryBundle:Account')->find($id);
        if($obj->getID() != null)
            return "
                <input type=\"hidden\" class=\"row_inv_id\" value=\"".$obj->getID()."\">".
                $obj->getName();

        else
            return "-";
    }

    public function formatNumeric($number)
    {
        return '<div class="numeric">'.$number.'</div>';
    }

    public function formatStock($id)
    {
        $em = $this->getDoctrine()->getManager();
        $stock = $em->getRepository('GistInventoryBundle:Stock')->findOneBy(array('product'=>$id));

        $product = $stock->getProduct();

        if ($stock->getQuantity() <= $product->getMinStock() || $stock->getQuantity() >= $product->getMaxStock())
        {
            return '<div class="numeric">'.number_format($stock->getQuantity(), 0).'</div>';
        }
        else
        {
            return '<div class="numeric">'.number_format($stock->getQuantity(), 0).'</div>';
        }
    }

    public function gridAction($pos_loc_id = -1)
    {
        $this->getControllerBase();
        $inv = $this->get('gist_inventory');
        $config = $this->get('gist_configuration');
        $gloader = $this->setupGridLoader();
        $grid = $this->get('gist_grid');
        $fg = $grid->newFilterGroup();

        if($pos_loc_id == 0)
        {
            $main_warehouse = $inv->findWarehouse($config->get('gist_main_warehouse'));
            $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getID()."')";
        }
        elseif ($pos_loc_id == -1)
        {
            $main_warehouse = $inv->findWarehouse($config->get('gist_main_warehouse'));
            $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getID()."')";
        }
        else
        {
            $selected_loc = $inv->findPOSLocation($pos_loc_id);
            $qry[] = "(o.inv_account = '".$selected_loc->getInventoryAccount()->getID()."')";
        }

        $qry[] = "(o.quantity > -900000)";

        if (!empty($qry))
        {
            $filter = implode(' AND ', $qry);
            $fg->where($filter);
            $gloader->setQBFilterGroup($fg);
        }


        $gres = $gloader->load();
        $resp = new Response($gres->getJSON());
        $resp->headers->set('Content-Type', 'application/json');

        return $resp;
    }

    public function gridSearchAction($pos_loc_id, $inv_type)
    {
        $this->getControllerBase();
        $inv = $this->get('gist_inventory');
        $config = $this->get('gist_configuration');
        $gloader = $this->setupGridLoader();

        $grid = $this->get('gist_grid');
        $fg = $grid->newFilterGroup();

        if($pos_loc_id == 0)
        {
            $main_warehouse = $inv->findWarehouse($config->get('gist_main_warehouse'));

            if ($inv_type == 'sales') {
                $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getID()."')";
            } elseif ($inv_type == 'damaged') {
                $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getDamagedContainer()->getID()."')";
            } elseif ($inv_type == 'tester') {
                $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getTesterContainer()->getID()."')";
            } elseif ($inv_type == 'missing') {
                $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getMissingContainer()->getID()."')";
            } else {
                $qry[] = "(o.inv_account = '".$main_warehouse->getInventoryAccount()->getID()."')";
            }


        }
        elseif ($pos_loc_id == -1)
        {

        }
        else
        {
            $selected_loc = $inv->findPOSLocation($pos_loc_id);
            if ($inv_type == 'sales') {
                $qry[] = "(o.inv_account = '".$selected_loc->getInventoryAccount()->getID()."')";
            } elseif ($inv_type == 'damaged') {
                $qry[] = "(o.inv_account = '".$selected_loc->getInventoryAccount()->getDamagedContainer()->getID()."')";
            } elseif ($inv_type == 'tester') {
                $qry[] = "(o.inv_account = '".$selected_loc->getInventoryAccount()->getTesterContainer()->getID()."')";
            } elseif ($inv_type == 'missing') {
                $qry[] = "(o.inv_account = '".$selected_loc->getInventoryAccount()->getMissingContainer()->getID()."')";
            } else {
                $qry[] = "(o.inv_account = '".$selected_loc->getInventoryAccount()->getID()."')";
            }
        }

        $qry[] = "(o.quantity > -90000)";


        if (!empty($qry))
        {
            $filter = implode(' AND ', $qry);
            $fg->where($filter);
            $gloader->setQBFilterGroup($fg);
        }
        else
        {

        }

        $gres = $gloader->load();
        $resp = new Response($gres->getJSON());
        $resp->headers->set('Content-Type', 'application/json');

        return $resp;
    }

    protected function getControllerBase()
    {
        $full = $this->getRequest()->get('_controller');
        $x_full = explode('\\', $full);

        $bundle = $x_full[0] . $x_full[1];
        $x_cont = explode('Controller:', $x_full[3]);
        $name = $x_cont[0];

        $base = $bundle . ':' . $name;

        if ($this->repo == null)
            $this->repo = $base;
        $this->base_view = $base;

        return $base;
    }

    protected function filterGrid(){
        $grid = $this->get('gist_grid');
        return $grid->newFilterGroup();
    }
}
