<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Controller\Admin\Product;

use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Eccube\Common\Constant;
use Eccube\Controller\Admin\AbstractCsvImportController;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Category;
use Eccube\Entity\Csv;
use Eccube\Entity\Master\CsvType;
use Eccube\Entity\Product;
use Eccube\Entity\ProductCategory;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ProductImage;
use Eccube\Entity\ProductStock;
use Eccube\Entity\ProductTag;
use Eccube\Form\Type\Admin\CsvImportType;
use Eccube\Repository\BaseInfoRepository;
use Eccube\Repository\CategoryRepository;
use Eccube\Repository\ClassCategoryRepository;
use Eccube\Repository\CsvRepository;
use Eccube\Repository\DeliveryDurationRepository;
use Eccube\Repository\Master\CsvTypeRepository;
use Eccube\Repository\Master\ProductStatusRepository;
use Eccube\Repository\Master\SaleTypeRepository;
use Eccube\Repository\ProductRepository;
use Eccube\Repository\TagRepository;
use Eccube\Service\CsvImportService;
use Eccube\Util\CacheUtil;
use Eccube\Util\StringUtil;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CsvImportController extends AbstractCsvImportController
{
    /**
     * @var DeliveryDurationRepository
     */
    protected $deliveryDurationRepository;

    /**
     * @var SaleTypeRepository
     */
    protected $saleTypeRepository;

    /**
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var ClassCategoryRepository
     */
    protected $classCategoryRepository;

    /**
     * @var ProductStatusRepository
     */
    protected $productStatusRepository;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var BaseInfo
     */
    protected $BaseInfo;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var CsvRepository
     */
    protected $csvRepository;

    /**
     * @var CsvTypeRepository
     */
    protected $csvTypeRepository;

    private $errors = [];

    /**
     * CsvImportController constructor.
     *
     * @param DeliveryDurationRepository $deliveryDurationRepository
     * @param SaleTypeRepository $saleTypeRepository
     * @param TagRepository $tagRepository
     * @param CategoryRepository $categoryRepository
     * @param ClassCategoryRepository $classCategoryRepository
     * @param ProductStatusRepository $productStatusRepository
     * @param ProductRepository $productRepository
     * @param BaseInfoRepository $baseInfoRepository
     * @param ValidatorInterface $validator
     *
     * @throws \Doctrine\ORM\NoResultException
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function __construct(
        DeliveryDurationRepository $deliveryDurationRepository,
        SaleTypeRepository $saleTypeRepository,
        TagRepository $tagRepository,
        CategoryRepository $categoryRepository,
        ClassCategoryRepository $classCategoryRepository,
        ProductStatusRepository $productStatusRepository,
        ProductRepository $productRepository,
        BaseInfoRepository $baseInfoRepository,
        ValidatorInterface $validator,
        CsvTypeRepository $csvTypeRepository,
        CsvRepository $csvRepository
    ) {
        $this->deliveryDurationRepository = $deliveryDurationRepository;
        $this->saleTypeRepository = $saleTypeRepository;
        $this->tagRepository = $tagRepository;
        $this->categoryRepository = $categoryRepository;
        $this->classCategoryRepository = $classCategoryRepository;
        $this->productStatusRepository = $productStatusRepository;
        $this->productRepository = $productRepository;
        $this->BaseInfo = $baseInfoRepository->get();
        $this->validator = $validator;
        $this->csvTypeRepository = $csvTypeRepository;
        $this->csvRepository = $csvRepository;
    }

    /**
     * ????????????CSV??????????????????
     *
     * @Route("/%eccube_admin_route%/product/product_csv_upload", name="admin_product_csv_import")
     * @Template("@admin/Product/csv_product.twig")
     */
    public function csvProduct(Request $request, CacheUtil $cacheUtil)
    {
        $form = $this->formFactory->createBuilder(CsvImportType::class)->getForm();
        $headers = $this->getProductCsvHeader();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $formFile = $form['import_file']->getData();
                if (!empty($formFile)) {
                    log_info('??????CSV????????????');
                    $data = $this->getImportData($formFile);
                    if ($data === false) {
                        $this->addErrors(trans('admin.common.csv_invalid_format'));

                        return $this->renderWithError($form, $headers, false);
                    }
                    $requireHeader = array_column(array_filter($headers, function ($item) {
                        return $item['required'];
                    }), 'display_name');

                    $dataColumnHeaders = $data->getColumnHeaders();

                    if (count(array_diff($requireHeader, $dataColumnHeaders)) > 0) {
                        $this->addErrors(trans('admin.common.csv_invalid_format'));

                        return $this->renderWithError($form, $headers, false);
                    }

                    $size = count($data);

                    if ($size < 1) {
                        $this->addErrors(trans('admin.common.csv_invalid_no_data'));

                        return $this->renderWithError($form, $headers, false);
                    }

                    $headerSize = count($dataColumnHeaders);
                    $headerByKey = array_column($headers, 'display_name', 'id');
                    $deleteImages = [];

                    $this->entityManager->getConfiguration()->setSQLLogger(null);
                    $this->entityManager->getConnection()->beginTransaction();
                    // CSV???????????????????????????
                    foreach ($data as $row) {
                        $line = $data->key() + 1;
                        if ($headerSize != count($row)) {
                            $message = trans('admin.common.csv_invalid_format_line', ['%line%' => $line]);
                            $this->addErrors($message);

                            return $this->renderWithError($form, $headers);
                        }

                        if (!isset($row[$headerByKey['id']]) || StringUtil::isBlank($row[$headerByKey['id']])) {
                            $Product = new Product();
                            $this->entityManager->persist($Product);
                        } else {
                            if (preg_match('/^\d+$/', $row[$headerByKey['id']])) {
                                $Product = $this->productRepository->find($row[$headerByKey['id']]);
                                if (!$Product) {
                                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['id']]);
                                    $this->addErrors($message);

                                    return $this->renderWithError($form, $headers);
                                }
                            } else {
                                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['id']]);
                                $this->addErrors($message);

                                return $this->renderWithError($form, $headers);
                            }

                            if (isset($row[$headerByKey['product_del_flg']])) {
                                if (StringUtil::isNotBlank($row[$headerByKey['product_del_flg']]) && $row[$headerByKey['product_del_flg']] == (string) Constant::ENABLED) {
                                    // ?????????????????????
                                    $deleteImages[] = $Product->getProductImage();

                                    try {
                                        $this->productRepository->delete($Product);
                                        $this->entityManager->flush();

                                        continue;
                                    } catch (ForeignKeyConstraintViolationException $e) {
                                        $message = trans('admin.common.csv_invalid_foreign_key', ['%line%' => $line, '%name%' => $Product->getName()]);
                                        $this->addErrors($message);

                                        return $this->renderWithError($form, $headers);
                                    }
                                }
                            }
                        }

                        if (StringUtil::isBlank($row[$headerByKey['status']])) {
                            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['status']]);
                            $this->addErrors($message);
                        } else {
                            if (preg_match('/^\d+$/', $row[$headerByKey['status']])) {
                                $ProductStatus = $this->productStatusRepository->find($row[$headerByKey['status']]);
                                if (!$ProductStatus) {
                                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['status']]);
                                    $this->addErrors($message);
                                } else {
                                    $Product->setStatus($ProductStatus);
                                }
                            } else {
                                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['status']]);
                                $this->addErrors($message);
                            }
                        }

                        if (StringUtil::isBlank($row[$headerByKey['name']])) {
                            $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['name']]);
                            $this->addErrors($message);

                            return $this->renderWithError($form, $headers);
                        } else {
                            $Product->setName(StringUtil::trimAll($row[$headerByKey['name']]));
                        }

                        if (isset($row[$headerByKey['note']]) && StringUtil::isNotBlank($row[$headerByKey['note']])) {
                            $Product->setNote(StringUtil::trimAll($row[$headerByKey['note']]));
                        } else {
                            $Product->setNote(null);
                        }

                        if (isset($row[$headerByKey['description_list']]) && StringUtil::isNotBlank($row[$headerByKey['description_list']])) {
                            $Product->setDescriptionList(StringUtil::trimAll($row[$headerByKey['description_list']]));
                        } else {
                            $Product->setDescriptionList(null);
                        }

                        if (isset($row[$headerByKey['description_detail']]) && StringUtil::isNotBlank($row[$headerByKey['description_detail']])) {
                            $Product->setDescriptionDetail(StringUtil::trimAll($row[$headerByKey['description_detail']]));
                        } else {
                            $Product->setDescriptionDetail(null);
                        }

                        if (isset($row[$headerByKey['search_word']]) && StringUtil::isNotBlank($row[$headerByKey['search_word']])) {
                            $Product->setSearchWord(StringUtil::trimAll($row[$headerByKey['search_word']]));
                        } else {
                            $Product->setSearchWord(null);
                        }

                        if (isset($row[$headerByKey['free_area']]) && StringUtil::isNotBlank($row[$headerByKey['free_area']])) {
                            $Product->setFreeArea(StringUtil::trimAll($row[$headerByKey['free_area']]));
                        } else {
                            $Product->setFreeArea(null);
                        }

                        // ??????????????????
                        $this->createProductImage($row, $Product, $data, $headerByKey);

                        $this->entityManager->flush();

                        // ????????????????????????
                        $this->createProductCategory($row, $Product, $data, $headerByKey);

                        //????????????
                        $this->createProductTag($row, $Product, $data, $headerByKey);

                        // ????????????????????????????????????????????????
                        /** @var ProductClass[] $ProductClasses */
                        $ProductClasses = $Product->getProductClasses();
                        if ($ProductClasses->count() < 1) {
                            // ????????????1(ID)??????????????????????????????????????????????????????????????????????????????
                            $ProductClassOrg = $this->createProductClass($row, $Product, $data, $headerByKey);
                            if ($this->BaseInfo->isOptionProductDeliveryFee()) {
                                if (isset($row[$headerByKey['delivery_fee']]) && StringUtil::isBlank($row[$headerByKey['delivery_fee']])) {
                                    $deliveryFee = str_replace(',', '', $row[$headerByKey['delivery_fee']]);
                                    $errors = $this->validator->validate($deliveryFee, new GreaterThanOrEqual(['value' => 0]));
                                    if ($errors->count() === 0) {
                                        $ProductClassOrg->setDeliveryFee($deliveryFee);
                                    } else {
                                        $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['delivery_fee']]);
                                        $this->addErrors($message);
                                    }
                                }
                            }

                            if (isset($row[$headerByKey['class_category1']]) && StringUtil::isNotBlank($row[$headerByKey['class_category1']])) {
                                if (isset($row[$headerByKey['class_category2']]) && $row[$headerByKey['class_category1']] == $row[$headerByKey['class_category2']]) {
                                    $message = trans('admin.common.csv_invalid_not_same', [
                                        '%line%' => $line,
                                        '%name1%' => $headerByKey['class_category1'],
                                        '%name2%' => $headerByKey['class_category2'],
                                    ]);
                                    $this->addErrors($message);
                                } else {
                                    // ??????????????????
                                    // ?????????????????????????????????
                                    $ProductClass = clone $ProductClassOrg;
                                    $ProductStock = clone $ProductClassOrg->getProductStock();

                                    // ????????????1???????????????2???null??????????????????????????????
                                    $ProductClassOrg->setVisible(false);

                                    // ????????????1???2?????????????????????????????????
                                    $ClassCategory1 = null;
                                    if (preg_match('/^\d+$/', $row[$headerByKey['class_category1']])) {
                                        $ClassCategory1 = $this->classCategoryRepository->find($row[$headerByKey['class_category1']]);
                                        if (!$ClassCategory1) {
                                            $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category1']]);
                                            $this->addErrors($message);
                                        } else {
                                            $ProductClass->setClassCategory1($ClassCategory1);
                                        }
                                    } else {
                                        $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category1']]);
                                        $this->addErrors($message);
                                    }

                                    if (isset($row[$headerByKey['class_category2']]) && StringUtil::isNotBlank($row[$headerByKey['class_category2']])) {
                                        if (preg_match('/^\d+$/', $row[$headerByKey['class_category2']])) {
                                            $ClassCategory2 = $this->classCategoryRepository->find($row[$headerByKey['class_category2']]);
                                            if (!$ClassCategory2) {
                                                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                                $this->addErrors($message);
                                            } else {
                                                if ($ClassCategory1 &&
                                                    ($ClassCategory1->getClassName()->getId() == $ClassCategory2->getClassName()->getId())
                                                ) {
                                                    $message = trans('admin.common.csv_invalid_not_same', ['%line%' => $line, '%name1%' => $headerByKey['class_category1'], '%name2%' => $headerByKey['class_category2']]);
                                                    $this->addErrors($message);
                                                } else {
                                                    $ProductClass->setClassCategory2($ClassCategory2);
                                                }
                                            }
                                        } else {
                                            $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                            $this->addErrors($message);
                                        }
                                    }
                                    $ProductClass->setProductStock($ProductStock);
                                    $ProductStock->setProductClass($ProductClass);

                                    $this->entityManager->persist($ProductClass);
                                    $this->entityManager->persist($ProductStock);
                                }
                            } else {
                                if (isset($row[$headerByKey['class_category2']]) && StringUtil::isNotBlank($row[$headerByKey['class_category2']])) {
                                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                    $this->addErrors($message);
                                }
                            }
                        } else {
                            // ?????????????????????
                            $flag = false;
                            $classCategoryId1 = StringUtil::isBlank($row[$headerByKey['class_category1']]) ? null : $row[$headerByKey['class_category1']];
                            $classCategoryId2 = StringUtil::isBlank($row[$headerByKey['class_category2']]) ? null : $row[$headerByKey['class_category2']];

                            foreach ($ProductClasses as $pc) {
                                $classCategory1 = is_null($pc->getClassCategory1()) ? null : $pc->getClassCategory1()->getId();
                                $classCategory2 = is_null($pc->getClassCategory2()) ? null : $pc->getClassCategory2()->getId();

                                // ??????????????????????????????????????????
                                if ($classCategory1 == $classCategoryId1 &&
                                    $classCategory2 == $classCategoryId2
                                ) {
                                    $this->updateProductClass($row, $Product, $pc, $data, $headerByKey);

                                    if ($this->BaseInfo->isOptionProductDeliveryFee()) {
                                        $headerByKey['delivery_fee'] = trans('csvimport.label.delivery_fee');
                                        if (isset($row[$headerByKey['delivery_fee']]) && StringUtil::isNotBlank($row[$headerByKey['delivery_fee']])) {
                                            $deliveryFee = str_replace(',', '', $row[$headerByKey['delivery_fee']]);
                                            $errors = $this->validator->validate($deliveryFee, new GreaterThanOrEqual(['value' => 0]));
                                            if ($errors->count() === 0) {
                                                $pc->setDeliveryFee($deliveryFee);
                                            } else {
                                                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['delivery_fee']]);
                                                $this->addErrors($message);
                                            }
                                        }
                                    }
                                    $flag = true;
                                    break;
                                }
                            }

                            // ?????????????????????
                            if (!$flag) {
                                $pc = $ProductClasses[0];
                                if ($pc->getClassCategory1() == null &&
                                    $pc->getClassCategory2() == null
                                ) {
                                    // ????????????1???????????????2???null??????????????????????????????
                                    $pc->setVisible(false);
                                }

                                if (isset($row[$headerByKey['class_category1']]) && isset($row[$headerByKey['class_category2']])
                                    && $row[$headerByKey['class_category1']] == $row[$headerByKey['class_category2']]) {
                                    $message = trans('admin.common.csv_invalid_not_same', [
                                        '%line%' => $line,
                                        '%name1%' => $headerByKey['class_category1'],
                                        '%name2%' => $headerByKey['class_category2'],
                                    ]);
                                    $this->addErrors($message);
                                } else {
                                    // ??????????????????1???????????????????????????
                                    // ????????????1???2?????????????????????????????????
                                    $ClassCategory1 = null;
                                    if (preg_match('/^\d+$/', $classCategoryId1)) {
                                        $ClassCategory1 = $this->classCategoryRepository->find($classCategoryId1);
                                        if (!$ClassCategory1) {
                                            $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category1']]);
                                            $this->addErrors($message);
                                        }
                                    } else {
                                        $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category1']]);
                                        $this->addErrors($message);
                                    }

                                    $ClassCategory2 = null;
                                    if (isset($row[$headerByKey['class_category2']]) && StringUtil::isNotBlank($row[$headerByKey['class_category2']])) {
                                        if ($pc->getClassCategory1() != null && $pc->getClassCategory2() == null) {
                                            $message = trans('admin.common.csv_invalid_can_not', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                            $this->addErrors($message);
                                        } else {
                                            if (preg_match('/^\d+$/', $classCategoryId2)) {
                                                $ClassCategory2 = $this->classCategoryRepository->find($classCategoryId2);
                                                if (!$ClassCategory2) {
                                                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                                    $this->addErrors($message);
                                                } else {
                                                    if ($ClassCategory1 &&
                                                        ($ClassCategory1->getClassName()->getId() == $ClassCategory2->getClassName()->getId())
                                                    ) {
                                                        $message = trans('admin.common.csv_invalid_not_same', [
                                                            '%line%' => $line,
                                                            '%name1%' => $headerByKey['class_category1'],
                                                            '%name2%' => $headerByKey['class_category2'],
                                                        ]);
                                                        $this->addErrors($message);
                                                    }
                                                }
                                            } else {
                                                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                                $this->addErrors($message);
                                            }
                                        }
                                    } else {
                                        if ($pc->getClassCategory1() != null && $pc->getClassCategory2() != null) {
                                            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                                            $this->addErrors($message);
                                        }
                                    }
                                    $ProductClass = $this->createProductClass($row, $Product, $data, $headerByKey, $ClassCategory1, $ClassCategory2);

                                    if ($this->BaseInfo->isOptionProductDeliveryFee()) {
                                        if (isset($row[$headerByKey['delivery_fee']]) && StringUtil::isNotBlank($row[$headerByKey['delivery_fee']])) {
                                            $deliveryFee = str_replace(',', '', $row[$headerByKey['delivery_fee']]);
                                            $errors = $this->validator->validate($deliveryFee, new GreaterThanOrEqual(['value' => 0]));
                                            if ($errors->count() === 0) {
                                                $ProductClass->setDeliveryFee($deliveryFee);
                                            } else {
                                                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['delivery_fee']]);
                                                $this->addErrors($message);
                                            }
                                        }
                                    }
                                    $Product->addProductClass($ProductClass);
                                }
                            }
                        }
                        if ($this->hasErrors()) {
                            return $this->renderWithError($form, $headers);
                        }
                        $this->entityManager->persist($Product);
                    }
                    $this->entityManager->flush();
                    $this->entityManager->getConnection()->commit();

                    // ???????????????????????????(commit?????????????????????)
                    foreach ($deleteImages as $images) {
                        foreach ($images as $image) {
                            try {
                                $fs = new Filesystem();
                                $fs->remove($this->eccubeConfig['eccube_save_image_dir'].'/'.$image);
                            } catch (\Exception $e) {
                                // ???????????????????????????????????????
                            }
                        }
                    }

                    log_info('??????CSV????????????');
                    $message = 'admin.common.csv_upload_complete';
                    $this->session->getFlashBag()->add('eccube.admin.success', $message);

                    $cacheUtil->clearDoctrineCache();
                }
            }
        }

        return $this->renderWithError($form, $headers);
    }

    /**
     * ??????????????????CSV??????????????????
     *
     * @Route("/%eccube_admin_route%/product/category_csv_upload", name="admin_product_category_csv_import")
     * @Template("@admin/Product/csv_category.twig")
     */
    public function csvCategory(Request $request, CacheUtil $cacheUtil)
    {
        $form = $this->formFactory->createBuilder(CsvImportType::class)->getForm();

        $headers = $this->getCategoryCsvHeader();
        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $formFile = $form['import_file']->getData();
                if (!empty($formFile)) {
                    log_info('????????????CSV????????????');
                    $data = $this->getImportData($formFile);
                    if ($data === false) {
                        $this->addErrors(trans('admin.common.csv_invalid_format'));

                        return $this->renderWithError($form, $headers, false);
                    }

                    $requireHeader = array_column(array_filter($headers, function ($value) {
                        return $value['required'];
                    }), 'display_name', 'id');

                    $headerByKey = array_column($headers, 'display_name', 'id');

                    $columnHeaders = $data->getColumnHeaders();
                    if (count(array_diff($requireHeader, $columnHeaders)) > 0) {
                        $this->addErrors(trans('admin.common.csv_invalid_format'));

                        return $this->renderWithError($form, $headers, false);
                    }

                    $size = count($data);
                    if ($size < 1) {
                        $this->addErrors(trans('admin.common.csv_invalid_no_data'));

                        return $this->renderWithError($form, $headers, false);
                    }
                    $this->entityManager->getConfiguration()->setSQLLogger(null);
                    $this->entityManager->getConnection()->beginTransaction();
                    // CSV???????????????????????????
                    foreach ($data as $row) {
                        /** @var $Category Category */
                        $Category = new Category();
                        if (isset($row[$headerByKey['id']]) && strlen($row[$headerByKey['id']]) > 0) {
                            if (!preg_match('/^\d+$/', $row[$headerByKey['id']])) {
                                $this->addErrors(($data->key() + 1).'?????????????????????ID????????????????????????');

                                return $this->renderWithError($form, $headers);
                            }
                            $Category = $this->categoryRepository->find($row[$headerByKey['id']]);
                            if (!$Category) {
                                $this->addErrors(($data->key() + 1).'?????????????????????ID????????????????????????');

                                return $this->renderWithError($form, $headers);
                            }
                            if ($row[$headerByKey['id']] == $row[$headerByKey['parent']]) {
                                $this->addErrors(($data->key() + 1).'?????????????????????ID??????????????????ID??????????????????');

                                return $this->renderWithError($form, $headers);
                            }
                        }

                        if (isset($row[$headerByKey['category_del_flg']]) && StringUtil::isNotBlank($row[$headerByKey['category_del_flg']])) {
                            if (StringUtil::trimAll($row[$headerByKey['category_del_flg']]) == 1) {
                                if ($Category->getId()) {
                                    log_info('????????????????????????', [$Category->getId()]);
                                    try {
                                        $this->categoryRepository->delete($Category);
                                        log_info('????????????????????????', [$Category->getId()]);
                                    } catch (ForeignKeyConstraintViolationException $e) {
                                        log_info('???????????????????????????', [$Category->getId(), $e]);
                                        $message = trans('admin.common.delete_error_foreign_key', ['%name%' => $Category->getName()]);
                                        $this->addError($message, 'admin');

                                        return $this->renderWithError($form, $headers);
                                    }
                                }

                                continue;
                            }
                        }

                        if (!isset($row[$headerByKey['name']]) || StringUtil::isBlank($row[$headerByKey['name']])) {
                            $this->addErrors(($data->key() + 1).'?????????????????????????????????????????????????????????');

                            return $this->renderWithError($form, $headers);
                        } else {
                            $Category->setName(StringUtil::trimAll($row[$headerByKey['name']]));
                        }

                        $ParentCategory = null;
                        if (isset($row[$headerByKey['parent']]) && StringUtil::isNotBlank($row[$headerByKey['parent']])) {
                            if (!preg_match('/^\d+$/', $row[$headerByKey['parent']])) {
                                $this->addErrors(($data->key() + 1).'????????????????????????ID????????????????????????');

                                return $this->renderWithError($form, $headers);
                            }

                            /** @var $ParentCategory Category */
                            $ParentCategory = $this->categoryRepository->find($row[$headerByKey['parent']]);
                            if (!$ParentCategory) {
                                $this->addErrors(($data->key() + 1).'????????????????????????ID????????????????????????');

                                return $this->renderWithError($form, $headers);
                            }
                        }
                        $Category->setParent($ParentCategory);

                        // Level
                        if (isset($row['??????']) && StringUtil::isNotBlank($row['??????'])) {
                            if ($ParentCategory == null && $row['??????'] != 1) {
                                $this->addErrors(($data->key() + 1).'????????????????????????ID????????????????????????');

                                return $this->renderWithError($form, $headers);
                            }
                            $level = StringUtil::trimAll($row['??????']);
                        } else {
                            $level = 1;
                            if ($ParentCategory) {
                                $level = $ParentCategory->getHierarchy() + 1;
                            }
                        }

                        $Category->setHierarchy($level);

                        if ($this->eccubeConfig['eccube_category_nest_level'] < $Category->getHierarchy()) {
                            $this->addErrors(($data->key() + 1).'???????????????????????????????????????????????????????????????????????????????????????');

                            return $this->renderWithError($form, $headers);
                        }

                        if ($this->hasErrors()) {
                            return $this->renderWithError($form, $headers);
                        }
                        $this->entityManager->persist($Category);
                        $this->categoryRepository->save($Category);
                    }

                    $this->entityManager->getConnection()->commit();
                    log_info('????????????CSV????????????');
                    $message = 'admin.common.csv_upload_complete';
                    $this->session->getFlashBag()->add('eccube.admin.success', $message);

                    $cacheUtil->clearDoctrineCache();
                }
            }
        }

        return $this->renderWithError($form, $headers);
    }

    /**
     * ?????????????????????CSV????????????????????????????????????
     *
     * @Route("/%eccube_admin_route%/product/csv_template/{type}", requirements={"type" = "\w+"}, name="admin_product_csv_template")
     */
    public function csvTemplate(Request $request, $type)
    {
        if ($type == 'product') {
            $headers = $this->getProductCsvHeader();
            $filename = 'product.csv';
        } elseif ($type == 'category') {
            $headers = $this->getCategoryCsvHeader();
            $filename = 'category.csv';
        } else {
            throw new NotFoundHttpException();
        }

        return $this->sendTemplateResponse($request, array_keys($headers), $filename);
    }

    /**
     * ??????????????????????????????????????????
     *
     * @param FormInterface $form
     * @param array $headers
     * @param bool $rollback
     *
     * @return array
     *
     * @throws \Doctrine\DBAL\ConnectionException
     */
    protected function renderWithError($form, $headers, $rollback = true)
    {
        if ($this->hasErrors()) {
            if ($rollback) {
                $this->entityManager->getConnection()->rollback();
            }
        }

        $this->removeUploadedFile();

        return [
            'form' => $form->createView(),
            'headers' => $headers,
            'errors' => $this->errors,
        ];
    }

    /**
     * ??????????????????????????????
     *
     * @param $row
     * @param Product $Product
     * @param CsvImportService $data
     */
    protected function createProductImage($row, Product $Product, $data, $headerByKey)
    {
        if (isset($row[$headerByKey['product_image']]) && StringUtil::isNotBlank($row[$headerByKey['product_image']])) {
            // ???????????????
            $ProductImages = $Product->getProductImage();
            foreach ($ProductImages as $ProductImage) {
                $Product->removeProductImage($ProductImage);
                $this->entityManager->remove($ProductImage);
            }

            // ???????????????
            $images = explode(',', $row[$headerByKey['product_image']]);

            $sortNo = 1;

            $pattern = "/\\$|^.*.\.\\\.*|\/$|^.*.\.\/\.*/";
            foreach ($images as $image) {
                $fileName = StringUtil::trimAll($image);

                // ????????????????????????????????????????????????
                if (strlen($fileName) > 0 && preg_match($pattern, $fileName)) {
                    $message = trans('admin.common.csv_invalid_image', ['%line%' => $data->key() + 1, '%name%' => $headerByKey['product_image']]);
                    $this->addErrors($message);
                } else {
                    // ???????????????????????????
                    if (!empty($fileName)) {
                        $ProductImage = new ProductImage();
                        $ProductImage->setFileName($fileName);
                        $ProductImage->setProduct($Product);
                        $ProductImage->setSortNo($sortNo);

                        $Product->addProductImage($ProductImage);
                        $sortNo++;
                        $this->entityManager->persist($ProductImage);
                    }
                }
            }
        }
    }

    /**
     * ????????????????????????????????????
     *
     * @param $row
     * @param Product $Product
     * @param CsvImportService $data
     * @param $headerByKey
     */
    protected function createProductCategory($row, Product $Product, $data, $headerByKey)
    {
        // ?????????????????????
        $ProductCategories = $Product->getProductCategories();
        foreach ($ProductCategories as $ProductCategory) {
            $Product->removeProductCategory($ProductCategory);
            $this->entityManager->remove($ProductCategory);
            $this->entityManager->flush();
        }

        if (isset($row[$headerByKey['product_categories']]) && StringUtil::isNotBlank($row[$headerByKey['product_categories']])) {
            // ?????????????????????
            $categories = explode(',', $row[$headerByKey['product_categories']]);
            $sortNo = 1;
            $categoriesIdList = [];
            foreach ($categories as $category) {
                $line = $data->key() + 1;
                if (preg_match('/^\d+$/', $category)) {
                    $Category = $this->categoryRepository->find($category);
                    if (!$Category) {
                        $message = trans('admin.common.csv_invalid_not_found_target', [
                            '%line%' => $line,
                            '%name%' => $headerByKey['product_categories'],
                            '%target_name%' => $category,
                        ]);
                        $this->addErrors($message);
                    } else {
                        foreach ($Category->getPath() as $ParentCategory) {
                            if (!isset($categoriesIdList[$ParentCategory->getId()])) {
                                $ProductCategory = $this->makeProductCategory($Product, $ParentCategory, $sortNo);
                                $this->entityManager->persist($ProductCategory);
                                $sortNo++;

                                $Product->addProductCategory($ProductCategory);
                                $categoriesIdList[$ParentCategory->getId()] = true;
                            }
                        }
                        if (!isset($categoriesIdList[$Category->getId()])) {
                            $ProductCategory = $this->makeProductCategory($Product, $Category, $sortNo);
                            $sortNo++;
                            $this->entityManager->persist($ProductCategory);
                            $Product->addProductCategory($ProductCategory);
                            $categoriesIdList[$Category->getId()] = true;
                        }
                    }
                } else {
                    $message = trans('admin.common.csv_invalid_not_found_target', [
                        '%line%' => $line,
                        '%name%' => $headerByKey['product_categories'],
                        '%target_name%' => $category,
                    ]);
                    $this->addErrors($message);
                }
            }
        }
    }

    /**
     * ???????????????
     *
     * @param array $row
     * @param Product $Product
     * @param CsvImportService $data
     */
    protected function createProductTag($row, Product $Product, $data, $headerByKey)
    {
        // ???????????????
        $ProductTags = $Product->getProductTag();
        foreach ($ProductTags as $ProductTag) {
            $Product->removeProductTag($ProductTag);
            $this->entityManager->remove($ProductTag);
        }

        if (isset($row[$headerByKey['product_tag']]) && StringUtil::isNotBlank($row[$headerByKey['product_tag']])) {
            // ???????????????
            $tags = explode(',', $row[$headerByKey['product_tag']]);
            foreach ($tags as $tag_id) {
                $Tag = null;
                if (preg_match('/^\d+$/', $tag_id)) {
                    $Tag = $this->tagRepository->find($tag_id);

                    if ($Tag) {
                        $ProductTags = new ProductTag();
                        $ProductTags
                            ->setProduct($Product)
                            ->setTag($Tag);

                        $Product->addProductTag($ProductTags);

                        $this->entityManager->persist($ProductTags);
                    }
                }
                if (!$Tag) {
                    $message = trans('admin.common.csv_invalid_not_found_target', [
                        '%line%' => $data->key() + 1,
                        '%name%' => $headerByKey['product_tag'],
                        '%target_name%' => $tag_id,
                    ]);
                    $this->addErrors($message);
                }
            }
        }
    }

    /**
     * ??????????????????1?????????????????????2???null????????????????????????????????????
     *
     * @param $row
     * @param Product $Product
     * @param CsvImportService $data
     * @param $headerByKey
     * @param null $ClassCategory1
     * @param null $ClassCategory2
     *
     * @return ProductClass
     */
    protected function createProductClass($row, Product $Product, $data, $headerByKey, $ClassCategory1 = null, $ClassCategory2 = null)
    {
        // ????????????1???????????????2???null????????????????????????
        $ProductClass = new ProductClass();
        $ProductClass->setProduct($Product);
        $ProductClass->setVisible(true);

        $line = $data->key() + 1;
        if (isset($row[$headerByKey['sale_type']]) && StringUtil::isNotBlank($row[$headerByKey['sale_type']])) {
            if (preg_match('/^\d+$/', $row[$headerByKey['sale_type']])) {
                $SaleType = $this->saleTypeRepository->find($row[$headerByKey['sale_type']]);
                if (!$SaleType) {
                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['sale_type']]);
                    $this->addErrors($message);
                } else {
                    $ProductClass->setSaleType($SaleType);
                }
            } else {
                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['sale_type']]);
                $this->addErrors($message);
            }
        } else {
            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['sale_type']]);
            $this->addErrors($message);
        }

        $ProductClass->setClassCategory1($ClassCategory1);
        $ProductClass->setClassCategory2($ClassCategory2);

        if (isset($row[$headerByKey['delivery_duration']]) && StringUtil::isNotBlank($row[$headerByKey['delivery_duration']])) {
            if (preg_match('/^\d+$/', $row[$headerByKey['delivery_duration']])) {
                $DeliveryDuration = $this->deliveryDurationRepository->find($row[$headerByKey['delivery_duration']]);
                if (!$DeliveryDuration) {
                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['delivery_duration']]);
                    $this->addErrors($message);
                } else {
                    $ProductClass->setDeliveryDuration($DeliveryDuration);
                }
            } else {
                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['delivery_duration']]);
                $this->addErrors($message);
            }
        }

        if (isset($row[$headerByKey['code']]) && StringUtil::isNotBlank($row[$headerByKey['code']])) {
            $ProductClass->setCode(StringUtil::trimAll($row[$headerByKey['code']]));
        } else {
            $ProductClass->setCode(null);
        }

        if (!isset($row[$headerByKey['stock_unlimited']])
            || StringUtil::isBlank($row[$headerByKey['stock_unlimited']])
            || $row[$headerByKey['stock_unlimited']] == (string) Constant::DISABLED
        ) {
            $ProductClass->setStockUnlimited(false);
            // ???????????????????????????????????????????????????
            if (isset($row[$headerByKey['stock']]) && StringUtil::isNotBlank($row[$headerByKey['stock']])) {
                $stock = str_replace(',', '', $row[$headerByKey['stock']]);
                if (preg_match('/^\d+$/', $stock) && $stock >= 0) {
                    $ProductClass->setStock($stock);
                } else {
                    $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['stock']]);
                    $this->addErrors($message);
                }
            } else {
                $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['stock']]);
                $this->addErrors($message);
            }
        } elseif ($row[$headerByKey['stock_unlimited']] == (string) Constant::ENABLED) {
            $ProductClass->setStockUnlimited(true);
            $ProductClass->setStock(null);
        } else {
            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['stock_unlimited']]);
            $this->addErrors($message);
        }

        if (isset($row[$headerByKey['sale_limit']]) && StringUtil::isNotBlank($row[$headerByKey['sale_limit']])) {
            $saleLimit = str_replace(',', '', $row[$headerByKey['sale_limit']]);
            if (preg_match('/^\d+$/', $saleLimit) && $saleLimit >= 0) {
                $ProductClass->setSaleLimit($saleLimit);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['sale_limit']]);
                $this->addErrors($message);
            }
        }

        if (isset($row[$headerByKey['price01']]) && StringUtil::isNotBlank($row[$headerByKey['price01']])) {
            $price01 = str_replace(',', '', $row[$headerByKey['price01']]);
            $errors = $this->validator->validate($price01, new GreaterThanOrEqual(['value' => 0]));
            if ($errors->count() === 0) {
                $ProductClass->setPrice01($price01);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['price01']]);
                $this->addErrors($message);
            }
        }

        if (isset($row[$headerByKey['price02']]) && StringUtil::isNotBlank($row[$headerByKey['price02']])) {
            $price02 = str_replace(',', '', $row[$headerByKey['price02']]);
            $errors = $this->validator->validate($price02, new GreaterThanOrEqual(['value' => 0]));
            if ($errors->count() === 0) {
                $ProductClass->setPrice02($price02);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['price02']]);
                $this->addErrors($message);
            }
        } else {
            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['price02']]);
            $this->addErrors($message);
        }

        if (isset($row[$headerByKey['delivery_fee']]) && StringUtil::isNotBlank($row[$headerByKey['delivery_fee']])) {
            $delivery_fee = str_replace(',', '', $row[$headerByKey['delivery_fee']]);
            $errors = $this->validator->validate($delivery_fee, new GreaterThanOrEqual(['value' => 0]));
            if ($errors->count() === 0) {
                $ProductClass->setDeliveryFee($delivery_fee);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['delivery_fee']]);
                $this->addErrors($message);
            }
        }

        $Product->addProductClass($ProductClass);
        $ProductStock = new ProductStock();
        $ProductClass->setProductStock($ProductStock);
        $ProductStock->setProductClass($ProductClass);

        if (!$ProductClass->isStockUnlimited()) {
            $ProductStock->setStock($ProductClass->getStock());
        } else {
            // ?????????????????????null?????????
            $ProductStock->setStock(null);
        }

        $this->entityManager->persist($ProductClass);
        $this->entityManager->persist($ProductStock);

        return $ProductClass;
    }

    /**
     * ???????????????????????????
     *
     * @param $row
     * @param Product $Product
     * @param ProductClass $ProductClass
     * @param CsvImportService $data
     *
     * @return ProductClass
     */
    protected function updateProductClass($row, Product $Product, ProductClass $ProductClass, $data, $headerByKey)
    {
        $ProductClass->setProduct($Product);

        $line = $data->key() + 1;
        if ($row[$headerByKey['sale_type']] == '') {
            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['sale_type']]);
            $this->addErrors($message);
        } else {
            if (preg_match('/^\d+$/', $row[$headerByKey['sale_type']])) {
                $SaleType = $this->saleTypeRepository->find($row[$headerByKey['sale_type']]);
                if (!$SaleType) {
                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['sale_type']]);
                    $this->addErrors($message);
                } else {
                    $ProductClass->setSaleType($SaleType);
                }
            } else {
                $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['sale_type']]);
                $this->addErrors($message);
            }
        }

        // ????????????1???2?????????????????????????????????
        if ($row[$headerByKey['class_category1']] != '') {
            if (preg_match('/^\d+$/', $row[$headerByKey['class_category1']])) {
                $ClassCategory = $this->classCategoryRepository->find($row[$headerByKey['class_category1']]);
                if (!$ClassCategory) {
                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category1']]);
                    $this->addErrors($message);
                } else {
                    $ProductClass->setClassCategory1($ClassCategory);
                }
            } else {
                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category1']]);
                $this->addErrors($message);
            }
        }

        if ($row[$headerByKey['class_category2']] != '') {
            if (preg_match('/^\d+$/', $row[$headerByKey['class_category2']])) {
                $ClassCategory = $this->classCategoryRepository->find($row[$headerByKey['class_category2']]);
                if (!$ClassCategory) {
                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                    $this->addErrors($message);
                } else {
                    $ProductClass->setClassCategory2($ClassCategory);
                }
            } else {
                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['class_category2']]);
                $this->addErrors($message);
            }
        }

        if ($row[$headerByKey['delivery_duration']] != '') {
            if (preg_match('/^\d+$/', $row[$headerByKey['delivery_duration']])) {
                $DeliveryDuration = $this->deliveryDurationRepository->find($row[$headerByKey['delivery_duration']]);
                if (!$DeliveryDuration) {
                    $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['delivery_duration']]);
                    $this->addErrors($message);
                } else {
                    $ProductClass->setDeliveryDuration($DeliveryDuration);
                }
            } else {
                $message = trans('admin.common.csv_invalid_not_found', ['%line%' => $line, '%name%' => $headerByKey['delivery_duration']]);
                $this->addErrors($message);
            }
        }

        if (StringUtil::isNotBlank($row[$headerByKey['code']])) {
            $ProductClass->setCode(StringUtil::trimAll($row[$headerByKey['code']]));
        } else {
            $ProductClass->setCode(null);
        }

        if (!isset($row[$headerByKey['stock_unlimited']])
            || StringUtil::isBlank($row[$headerByKey['stock_unlimited']])
            || $row[$headerByKey['stock_unlimited']] == (string) Constant::DISABLED
        ) {
            $ProductClass->setStockUnlimited(false);
            // ???????????????????????????????????????????????????
            if ($row[$headerByKey['stock']] == '') {
                $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['stock']]);
                $this->addErrors($message);
            } else {
                $stock = str_replace(',', '', $row[$headerByKey['stock']]);
                if (preg_match('/^\d+$/', $stock) && $stock >= 0) {
                    $ProductClass->setStock($row[$headerByKey['stock']]);
                } else {
                    $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['stock']]);
                    $this->addErrors($message);
                }
            }
        } elseif ($row[$headerByKey['stock_unlimited']] == (string) Constant::ENABLED) {
            $ProductClass->setStockUnlimited(true);
            $ProductClass->setStock(null);
        } else {
            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['stock_unlimited']]);
            $this->addErrors($message);
        }

        if ($row[$headerByKey['sale_limit']] != '') {
            $saleLimit = str_replace(',', '', $row[$headerByKey['sale_limit']]);
            if (preg_match('/^\d+$/', $saleLimit) && $saleLimit >= 0) {
                $ProductClass->setSaleLimit($saleLimit);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['sale_limit']]);
                $this->addErrors($message);
            }
        }

        if ($row[$headerByKey['price01']] != '') {
            $price01 = str_replace(',', '', $row[$headerByKey['price01']]);
            $errors = $this->validator->validate($price01, new GreaterThanOrEqual(['value' => 0]));
            if ($errors->count() === 0) {
                $ProductClass->setPrice01($price01);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['price01']]);
                $this->addErrors($message);
            }
        }

        if ($row[$headerByKey['price02']] == '') {
            $message = trans('admin.common.csv_invalid_required', ['%line%' => $line, '%name%' => $headerByKey['price02']]);
            $this->addErrors($message);
        } else {
            $price02 = str_replace(',', '', $row[$headerByKey['price02']]);
            $errors = $this->validator->validate($price02, new GreaterThanOrEqual(['value' => 0]));
            if ($errors->count() === 0) {
                $ProductClass->setPrice02($price02);
            } else {
                $message = trans('admin.common.csv_invalid_greater_than_zero', ['%line%' => $line, '%name%' => $headerByKey['price02']]);
                $this->addErrors($message);
            }
        }

        $ProductStock = $ProductClass->getProductStock();

        if (!$ProductClass->isStockUnlimited()) {
            $ProductStock->setStock($ProductClass->getStock());
        } else {
            // ?????????????????????null?????????
            $ProductStock->setStock(null);
        }

        return $ProductClass;
    }

    /**
     * ??????????????????????????????????????????
     */
    protected function addErrors($message)
    {
        $this->errors[] = $message;
    }

    /**
     * @return array
     */
    protected function getErrors()
    {
        return $this->errors;
    }

    /**
     * @return boolean
     */
    protected function hasErrors()
    {
        return count($this->getErrors()) > 0;
    }

    /**
     * ????????????CSV??????????????????
     *
     * @return array
     */
    private function getProductCsvHeader()
    {
        $oldData = [
            trans('admin.product.product_csv.product_id_col') => [
                'id' => 'id',
                'description' => 'admin.product.product_csv.product_id_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.display_status_col') => [
                'id' => 'status',
                'description' => 'admin.product.product_csv.display_status_description',
                'required' => true,
            ],
            trans('admin.product.product_csv.product_name_col') => [
                'id' => 'name',
                'description' => 'admin.product.product_csv.product_name_description',
                'required' => true,
            ],
            trans('admin.product.product_csv.shop_memo_col') => [
                'id' => 'note',
                'description' => 'admin.product.product_csv.shop_memo_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.description_list_col') => [
                'id' => 'description_list',
                'description' => 'admin.product.product_csv.description_list_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.description_detail_col') => [
                'id' => 'description_detail',
                'description' => 'admin.product.product_csv.description_detail_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.keyword_col') => [
                'id' => 'search_word',
                'description' => 'admin.product.product_csv.keyword_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.free_area_col') => [
                'id' => 'free_area',
                'description' => 'admin.product.product_csv.free_area_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.delete_flag_col') => [
                'id' => 'product_del_flg',
                'description' => 'admin.product.product_csv.delete_flag_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.product_image_col') => [
                'id' => 'product_image',
                'description' => 'admin.product.product_csv.product_image_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.category_col') => [
                'id' => 'product_categories',
                'description' => 'admin.product.product_csv.category_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.tag_col') => [
                'id' => 'product_tag',
                'description' => 'admin.product.product_csv.tag_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.sale_type_col') => [
                'id' => 'sale_type',
                'description' => 'admin.product.product_csv.sale_type_description',
                'required' => true,
            ],
            trans('admin.product.product_csv.class_category1_col') => [
                'id' => 'class_category1',
                'description' => 'admin.product.product_csv.class_category1_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.class_category2_col') => [
                'id' => 'class_category2',
                'description' => 'admin.product.product_csv.class_category2_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.delivery_duration_col') => [
                'id' => 'delivery_duration',
                'description' => 'admin.product.product_csv.delivery_duration_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.product_code_col') => [
                'id' => 'code',
                'description' => 'admin.product.product_csv.product_code_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.stock_col') => [
                'id' => 'stock',
                'description' => 'admin.product.product_csv.stock_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.stock_unlimited_col') => [
                'id' => 'stock_unlimited',
                'description' => 'admin.product.product_csv.stock_unlimited_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.sale_limit_col') => [
                'id' => 'sale_limit',
                'description' => 'admin.product.product_csv.sale_limit_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.normal_price_col') => [
                'id' => 'price01',
                'description' => 'admin.product.product_csv.normal_price_description',
                'required' => false,
            ],
            trans('admin.product.product_csv.sale_price_col') => [
                'id' => 'price02',
                'description' => 'admin.product.product_csv.sale_price_description',
                'required' => true,
            ],
            trans('admin.product.product_csv.delivery_fee_col') => [
                'id' => 'delivery_fee',
                'description' => 'admin.product.product_csv.delivery_fee_description',
                'required' => false,
            ],
        ];

        $productCsvType = $this->csvTypeRepository->find(CsvType::CSV_TYPE_PRODUCT);
        /** @var Csv[] $productCsv */
        $productCsv = $this->csvRepository->findBy(['CsvType' => $productCsvType]);

        $oldData = $this->getDisplayNameByCsv($oldData, $productCsv);

        return $oldData;
    }

    /**
     * ????????????CSV??????????????????
     */
    private function getCategoryCsvHeader()
    {
        $data = [
            trans('admin.product.category_csv.category_id_col') => [
                'id' => 'id',
                'description' => 'admin.product.category_csv.category_id_description',
                'required' => false,
            ],
            trans('admin.product.category_csv.category_name_col') => [
                'id' => 'name',
                'description' => 'admin.product.category_csv.category_name_description',
                'required' => true,
            ],
            trans('admin.product.category_csv.parent_category_id_col') => [
                'id' => 'parent',
                'description' => 'admin.product.category_csv.parent_category_id_description',
                'required' => false,
            ],
            trans('admin.product.category_csv.delete_flag_col') => [
                'id' => 'category_del_flg',
                'description' => 'admin.product.category_csv.delete_flag_description',
                'required' => false,
            ],
        ];

        $csvType = $this->csvTypeRepository->find(CsvType::CSV_TYPE_CATEGORY);
        /** @var Csv[] $categoryCsv */
        $categoryCsv = $this->csvRepository->findBy(['CsvType' => $csvType]);

        $data = $this->getDisplayNameByCsv($data, $categoryCsv);

        return $data;
    }

    /**
     * ProductCategory??????
     *
     * @param \Eccube\Entity\Product $Product
     * @param \Eccube\Entity\Category $Category
     * @param int $sortNo
     *
     * @return ProductCategory
     */
    private function makeProductCategory($Product, $Category, $sortNo)
    {
        $ProductCategory = new ProductCategory();
        $ProductCategory->setProduct($Product);
        $ProductCategory->setProductId($Product->getId());
        $ProductCategory->setCategory($Category);
        $ProductCategory->setCategoryId($Category->getId());

        return $ProductCategory;
    }

    /**
     * @param $oldData
     * @param $productCsv
     * @return mixed
     */
    private function getDisplayNameByCsv($oldData, $productCsv)
    {
        foreach ($oldData as $datum => $oldDatum) {
            $oldData[$datum]['display_name'] = $datum;
            /**
             * @var  $index
             * @var Csv $csv
             */
            foreach ($productCsv as $index => $csv) {
                $key = StringUtil::toUnderscores($csv->getFieldName());
                if ($key == $oldDatum['id']) {
                    if (is_null($csv->getReferenceFieldName())
                        || $csv->getReferenceFieldName() == 'id'
                        || strpos($csv->getReferenceFieldName(), '_id')
                        || $csv->getReferenceFieldName() == 'file_name'
                    ) {
                        $oldData[$datum]['display_name'] = $csv->getDispName();
                        break;
                    }
                }
            }
        }

        return $oldData;
    }
}
