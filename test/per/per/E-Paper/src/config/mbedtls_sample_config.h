/**
 * 
 * mbedTLS是一个开源的加密库，提供了各种密码学功能和协议支持，包括对称加密、公钥加密、
 * 哈希函数、数字签名、TLS/SSL协议等。它被设计为轻量级、高效和易于使用，适用于嵌入式
 * 设备和资源受限的环境。http://www.apache.org/licenses/LICENSE-2.0
 */

#ifndef MBEDTLS_CONFIG_H
#define MBEDTLS_CONFIG_H

#if defined(_MSC_VER) && !defined(_CRT_SECURE_NO_DEPRECATE)
#define _CRT_SECURE_NO_DEPRECATE 1
#endif

#define MBEDTLS_PLATFORM_MEMORY /*启用平台内存支持 */
#define MBEDTLS_PLATFORM_NO_STD_FUNCTIONS // 禁用标准库函数支持

#define MBEDTLS_CIPHER_MODE_CBC // 启用CBC模式的对称加密
#define MBEDTLS_CIPHER_MODE_CTR // 启用CTR模式的对称加密

#define MBEDTLS_CIPHER_PADDING_PKCS7 // 启用PKCS7填充方式
#define MBEDTLS_CIPHER_PADDING_ZEROS // 启用零填充方式
#define MBEDTLS_REMOVE_ARC4_CIPHERSUITES // 移除ARC4密码套件
#define MBEDTLS_REMOVE_3DES_CIPHERSUITES // 移除3DES密码套件

#define MBEDTLS_ECDH_C // 启用ECDH密钥交换
#define MBEDTLS_ECDSA_C // 启用ECDSA数字签名
//#define MBEDTLS_ECP_DP_SECP192R1_ENABLED 
//#define MBEDTLS_ECP_DP_SECP224R1_ENABLED
#define MBEDTLS_ECP_DP_SECP256R1_ENABLED // 启用secp256r1曲线
//#define MBEDTLS_ECP_DP_SECP384R1_ENABLED
//#define MBEDTLS_ECP_DP_SECP521R1_ENABLED
//#define MBEDTLS_ECP_DP_SECP192K1_ENABLED
//#define MBEDTLS_ECP_DP_SECP224K1_ENABLED
//#define MBEDTLS_ECP_DP_SECP256K1_ENABLED
//#define MBEDTLS_ECP_DP_BP256R1_ENABLED
//#define MBEDTLS_ECP_DP_BP384R1_ENABLED
//#define MBEDTLS_ECP_DP_BP512R1_ENABLED
//#define MBEDTLS_ECP_DP_CURVE25519_ENABLED
//#define MBEDTLS_ECP_DP_CURVE448_ENABLED

#define MBEDTLS_ECP_NIST_OPTIM  // 

#define MBEDTLS_KEY_EXCHANGE_PSK_ENABLED  // 启用PSK密钥交换
#define MBEDTLS_KEY_EXCHANGE_RSA_ENABLED // 启用RSA密钥交换
#define MBEDTLS_KEY_EXCHANGE_DHE_RSA_ENABLED //  启用DHE_RSA密钥交换
#define MBEDTLS_KEY_EXCHANGE_ECDHE_RSA_ENABLED  // 启用ECDHE_RSA密钥交换
#define MBEDTLS_KEY_EXCHANGE_ECDHE_ECDSA_ENABLED  // 启用ECDHE_ECDSA密钥交换
#define MBEDTLS_KEY_EXCHANGE_ECDH_ECDSA_ENABLED  // 启用ECDH_ECDSA密钥交换
#define MBEDTLS_KEY_EXCHANGE_ECDH_RSA_ENABLED  //  启用ECDH_RSA密钥交换

//XXX TODO remove bl606p
#if defined(CFG_CHIP_BL606P) || defined(CFG_CHIP_BL808)
#define MBEDTLS_PKCS5_C/*启用PKCS5密码学标准*/
#endif
#define MBEDTLS_PKCS1_V15 // 启用PKCS1 v1.5
#define MBEDTLS_PKCS1_V21 // 启用PKCS1 v2.1

#define MBEDTLS_SSL_MAX_FRAGMENT_LENGTH // 启用最大片段长度扩展
#define MBEDTLS_SSL_PROTO_TLS1_2  // 启用TLS 1.2协议
#define MBEDTLS_SSL_ALPN // 启用应用层协议协商
#define MBEDTLS_SSL_SESSION_TICKETS // 启用会话票据
#define MBEDTLS_SSL_SERVER_NAME_INDICATION // 启用服务器名称指示
#define MBEDTLS_X509_CHECK_KEY_USAGE // 启用X.509证书密钥用法检查
#define MBEDTLS_X509_CHECK_EXTENDED_KEY_USAGE // 启用X.509证书扩展密钥用法检查

#define MBEDTLS_AES_C // 启用AES加密
#define MBEDTLS_AES_ROM_TABLES  // 启用ROM表格实现的AES
#define MBEDTLS_BASE64_C  //  启用Base64编解码
#define MBEDTLS_ASN1_PARSE_C  // 启用ASN.1解析
#define MBEDTLS_ASN1_WRITE_C // 启用ASN.1写入
#define MBEDTLS_BIGNUM_C // 启用大数运算
#define MBEDTLS_CIPHER_C  // 启用通用加密接口
#define MBEDTLS_CTR_DRBG_C // 启用CTR_DRBG随机数生成器
#define MBEDTLS_DEBUG_C // 启用调试支持
#define MBEDTLS_ECP_C  // 启用椭圆曲线密码学
#define MBEDTLS_ENTROPY_C  // 启用熵源

#define MBEDTLS_ERROR_C // 启用错误处理
#define MBEDTLS_GCM_C  // 启用GCM模式
#define MBEDTLS_MD_C  // 启用消息摘要
#define MBEDTLS_MD5_C  // 启用MD5消息摘要
#define MBEDTLS_OID_C  // 启用OID解析
#define MBEDTLS_PEM_PARSE_C  // 启用PEM解析
#define MBEDTLS_PK_C  // 启用公钥加密
#define MBEDTLS_PK_PARSE_C  // 启用公钥解析

#define MBEDTLS_PLATFORM_C  // 启用平台支持
#define MBEDTLS_GENPRIME  // 启用大素数生成
#define MBEDTLS_RSA_C  // 启用RSA加密
#define MBEDTLS_DHM_C  // 启用DHM密钥交换
#define MBEDTLS_SHA1_C  // 启用SHA-1哈希算法
#define MBEDTLS_SHA256_C  // 启用SHA-256哈希算法
#define MBEDTLS_SHA512_C  // 启用SHA-512哈希算法

#define MBEDTLS_SSL_COOKIE_C  // 启用SSL cookie
#define MBEDTLS_SSL_CLI_C  // 启用SSL客户端
#define MBEDTLS_SSL_TLS_C  // 启用SSL/TLS协议
#define MBEDTLS_X509_USE_C  // 启用X.509证书
#define MBEDTLS_X509_CRT_PARSE_C // 启用X.509证书解析

//#define MBEDTLS_NET_C

//#define MBEDTLS_FS_IO

#define MBEDTLS_NO_PLATFORM_ENTROPY // 禁用平台熵源
#define MBEDTLS_ENTROPY_HARDWARE_ALT //  启用硬件熵源

#define MBEDTLS_PLATFORM_STD_MEM_HDR "mbedtls_port_bouffalo_sdk.h" // 平台内存头文件

// 定义BL_MPI_LARGE_NUM_SOFTWARE_MPI以允许对非常大的大数进行操作
/* #define BL_MPI_LARGE_NUM_SOFTWARE_MPI */

// Hash HW
#ifdef CONFIG_MBEDTLS_SHA1_USE_HW
#define MBEDTLS_SHA1_ALT
#endif

#ifdef CONFIG_MBEDTLS_SHA256_USE_HW
#define MBEDTLS_SHA256_ALT
#endif

#ifdef CONFIG_MBEDTLS_SHA512_USE_HW
#define MBEDTLS_SHA512_ALT
#endif

// AES HW
#ifdef CONFIG_MBEDTLS_AES_USE_HW
#define MBEDTLS_AES_ALT
#endif

// ECC HW
#ifdef CONFIG_MBEDTLS_ECC_USE_HW
#define MBEDTLS_ECP_ALT
#endif

#if defined(CONFIG_MBEDTLS_ECC_USE_HW) && defined(MBEDTLS_ECP_RESTARTABLE)
#error "ECP Restartable is not implemented with ECP HW acceleration!"
#endif

/* Target and application specific configurations
 *
 * Allow user to override any previous default.
 *
 */
#if defined(MBEDTLS_USER_CONFIG_FILE)
#include MBEDTLS_USER_CONFIG_FILE
#endif

#if defined(MBEDTLS_PSA_CRYPTO_CONFIG)
#include "mbedtls/config_psa.h"
#endif

#include "mbedtls/check_config.h"

#endif /* MBEDTLS_CONFIG_H */
