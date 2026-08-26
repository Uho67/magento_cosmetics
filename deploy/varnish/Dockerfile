FROM varnish:7.7-alpine

COPY default.vcl /etc/varnish/default.vcl

EXPOSE 6081

CMD ["varnishd", \
     "-F", \
     "-f", "/etc/varnish/default.vcl", \
     "-s", "malloc,256m", \
     "-p", "thread_pools=4", \
     "-p", "thread_pool_min=5", \
     "-p", "thread_pool_max=500", \
     "-T", "localhost:6082", \
     "-a", "0.0.0.0:6081"]
