<?php

/*
 * 多路是指多个客户端连接socket，复用就是指复用少数几个进程，多路复用本身依然隶属于同步通信方式
 * 只是表现出的结果看起来像异步
 * 本文采用 select 方式
 * int socket_select ( array &$read , array &$write , array &$except , int $tv_sec [, int $tv_usec = 0 ] )
 * 值得注意的是$read，$write，$except三个参数前面都有一个&，也就是说这三个参数是引用类型的，是可以被改写内容的
 * 所以，需要进程内维护一个连接的 fd 数组，每次调用 select 的时候，将连接的 fd 传入 select 进程
 * 每次调用 select ,把监听的 socket fd 从用户态拷贝到内核态，在内核中遍历，修改可读/可写的 fd 的 umask 掩码
 * select 获取到可读/可写时间后，将 fd_set 从内核态拷贝到用户态，由用户进程遍历 fd_set 获取可读/可写的 socket
 */


// BEGIN 创建一个tcp socket服务器
$host = '0.0.0.0';
$port = 9999;
$listen_socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
socket_bind( $listen_socket, $host, $port );
socket_listen( $listen_socket );
// END 创建服务器完毕
// 也将监听socket放入到read fd set中去，因为select也要监听listen_socket上发生事件
$client = [ $listen_socket ];
// 先暂时只引入读事件，避免有同学晕头
$write = [];
$exp = [];
// 开始进入循环
while( true ){
    $read = $client;
    // 当select监听到了fd变化，注意第四个参数为null
    // 如果写成大于0的整数那么表示将在规定时间内超时
    // 如果写成等于0的整数那么表示不断调用select，执行后立马返回，然后继续
    // 如果写成null，那么表示select会阻塞一直到监听发生变化
    if( socket_select( $read, $write, $exp, null ) > 0 ){
        // 判断listen_socket有没有发生变化，如果有就是有客户端发生连接操作了
        if( in_array( $listen_socket, $read ) ){
            // 将客户端socket加入到client数组中
            $client_socket = socket_accept( $listen_socket );
            $client[] = $client_socket;
            // 然后将listen_socket从read中去除掉
            $key = array_search( $listen_socket, $read );
            unset( $read[ $key ] );
        }
        // 查看去除listen_socket中是否还有client_socket
        if( count( $read ) > 0 ){
            $msg = 'hello world';
            foreach( $read as $socket_item ){
                // 从可读取的fd中读取出来数据内容，然后发送给其他客户端
                $content = socket_read( $socket_item, 2048 );
                // 循环client数组，将内容发送给其余所有客户端
                foreach( $client as $client_socket ){
                    // 因为client数组中包含了 listen_socket 以及当前发送者自己socket，所以需要排除二者
                    if( $client_socket != $listen_socket && $client_socket != $socket_item ){
                        socket_write( $client_socket, $content, strlen( $content ) );
                    }
                }
            }
        }
    }
    // 当select没有监听到可操作fd的时候，直接continue进入下一次循环
    else {
        continue;
    }
}

/*
 * 在上面代码案例中，服务器代码第一次执行的时候，我们要把需要监听的所有fd全部放到了read数组中
 * 然而在当系统经历了select后，这个数组的内容就会发生改变，由原来的全部read fds变成了只包含可读的read fds
 * 这也就是为什么声明了一个client数组，然后又声明了一个read数组，然后read = client
 * 如果我们直接将client当作socket_select的参数，那么client数组内容就被修改
 * 假如有5个用户保存在client数组中，只有1个可读，在经过socket_select后client中就只剩下那个可读的fd了，其余4个客户端将会丢失，此时客户端的表现就是连接莫名其妙发生丢失了．
 */
